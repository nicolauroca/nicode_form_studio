<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Contract\RateLimiterInterface;
use Nicode\FormStudio\Domain\{FieldAddress, RepeatedInstances};
use Nicode\FormStudio\Infrastructure\Database\FormRepository;
use Nicode\FormStudio\Registry\ProviderDependencies;
use Nicode\FormStudio\Security\{AttemptTokens, PublicAccess};
use Nicode\FormStudio\Submission\{RepeatedSubmitRequest, RequestContext, SubmissionFailure};

/** Edit presentation rows only; never creates a submission or stages an upload. */
final readonly class FormRows
{
    public function __construct(private FormRepository $forms, private PublicAccess $access, private AttemptTokens $attempts, private RateLimiterInterface $limiter, private ProviderDependencies $dependencies) {}

    public function change(RepeatedSubmitRequest $envelope, RequestContext $context, string $operation, string $group, ?string $row = null): array
    {
        if (!$context->csrfValid) { throw new SubmissionFailure('session_error'); }
        $request = $envelope->request;
        $form = $this->forms->get($request->formId);
        $this->access->assert($form, $context->viewLevels, $context->language, time());
        if ((int) $form['published_version_id'] !== $request->versionId) { throw new \OutOfBoundsException('Form version unavailable.'); }
        $spec = $this->forms->version($request->formId, $request->versionId); $definition = $spec->toArray();
        try { $this->attempts->verify($request->attempt, $request->formId, $request->versionId, $context->sessionBinding . ':' . $context->channel, maximumSeconds: $definition['security']['attempt_lifetime'] ?? 7200); }
        catch (\DomainException) { throw new SubmissionFailure('session_error'); }
        $rate = $this->limiter->consume(hash('sha256', 'rows:' . $request->formId . ':' . $context->rateScope), 120, 60);
        if (!$rate->allowed) { throw new SubmissionFailure('rate_limited', retryAfter: $rate->retryAfter); }
        $this->dependencies->assert($spec);
        // Never trust the envelope's layout: membership belongs to this published snapshot.
        $instances = new RepeatedInstances($definition['elements'], $envelope->instances->declarations());
        $values = $instances->bind($request->values);
        $address = FieldAddress::fromKey($group);
        $changed = match (true) {
            $operation === 'add' && $row === null => $instances->withAddedRow($address),
            $operation === 'remove' && $row !== null => $instances->withRemovedRow($address, $row),
            default => throw new \InvalidArgumentException('Invalid row operation.'),
        };
        $allowed = [];
        foreach ($changed->addresses() as $field) { $allowed[$field->key()] = true; }
        // Old omitted controls remain explicitly empty. Only brand-new fields lack a
        // value so presentation can initialize them without restoring cleared siblings.
        return ['instances' => $changed->declarations(), 'values' => array_intersect_key($values, $allowed)];
    }
}
