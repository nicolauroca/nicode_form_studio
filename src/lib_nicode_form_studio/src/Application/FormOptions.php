<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Contract\RateLimiterInterface;
use Nicode\FormStudio\Infrastructure\Database\FormRepository;
use Nicode\FormStudio\Registry\ProviderDependencies;
use Nicode\FormStudio\Security\{AttemptTokens, PublicAccess};
use Nicode\FormStudio\Submission\{RequestContext, SubmitRequest, SubmissionFailure};
use Nicode\FormStudio\Validation\ValidationEngine;

/** Read-only options query; the submission pipeline repeats authoritative validation. */
final readonly class FormOptions
{
    public function __construct(private FormRepository $forms, private PublicAccess $access, private AttemptTokens $attempts, private ValidationEngine $validation, private RateLimiterInterface $limiter, private ProviderDependencies $dependencies) {}
    public function resolve(SubmitRequest $request, RequestContext $context): array
    {
        return $this->process($request, $context);
    }
    public function resolveInstances(\Nicode\FormStudio\Submission\RepeatedSubmitRequest $request, RequestContext $context): array
    {
        return $this->process($request->request, $context, $request->instances->declarations());
    }
    private function process(SubmitRequest $request, RequestContext $context, ?array $declarations = null): array
    {
        if (!$context->csrfValid) { throw new SubmissionFailure('session_error'); }
        $form = $this->forms->get($request->formId); $this->access->assert($form, $context->viewLevels, $context->language, time());
        if ((int) $form['published_version_id'] !== $request->versionId) { throw new \OutOfBoundsException('Form version unavailable.'); }
        $spec = $this->forms->version($request->formId, $request->versionId); $definition = $spec->toArray();
        try { $this->attempts->verify($request->attempt, $request->formId, $request->versionId, $context->sessionBinding . ':' . $context->channel, maximumSeconds: $definition['security']['attempt_lifetime'] ?? 7200); }
        catch (\DomainException) { throw new SubmissionFailure('session_error'); }
        $rate = $this->limiter->consume(hash('sha256', 'options:' . $request->formId . ':' . $context->rateScope), 120, 60);
        if (!$rate->allowed) { throw new SubmissionFailure('rate_limited', retryAfter: $rate->retryAfter); }
        $this->dependencies->assert($spec);
        $spec = \Nicode\FormStudio\Translation\DefinitionTranslations::spec($spec, $context->language);
        $definition = $spec->toArray(); $fields = $definition['fields'];
        if ($declarations !== null) {
            $instances = new \Nicode\FormStudio\Domain\RepeatedInstances($definition['elements'], $declarations);
            $instances->bind($request->values);
            $byUuid = array_column($fields, null, 'uuid'); $fields = [];
            foreach ($instances->addresses() as $address) { $field = $byUuid[$address->field]; $field['uuid'] = $address->key(); $fields[] = $field; }
        } elseif (in_array('repeatable-group', array_column($definition['elements'], 'type'), true)) { throw new \InvalidArgumentException('Repeated options require row declarations.'); }
        $validated = $declarations === null
            ? $this->validation->validate($spec, $request->values, $context->trustedValues, $context->ruleContext())
            : $this->validation->validateInstances($spec, $declarations, $request->values, $context->trustedValues, $context->ruleContext());
        $result = [];
        foreach ($fields as $field) {
            if (!isset($field['source']) || in_array($field['source']['type'], ['static', 'option_set'], true)) { continue; }
            $state = $validated->rules->states[$field['uuid']]; $options = [];
            if ($state['active']) {
                foreach ($state['options'] as $option) {
                    $public = ['value' => $option['value'], 'label' => $option['label'], 'enabled' => (bool) ($option['enabled'] ?? true)];
                    if (array_key_exists('default', $option)) { $public['default'] = $option['default']; }
                    $options[] = $public;
                }
            }
            $result[$field['uuid']] = $options;
        }
        return $result;
    }
}
