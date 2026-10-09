<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Jobs;
use Nicode\FormStudio\Actions\{ActionContext, ActionEngine};
use Nicode\FormStudio\Contract\JobHandlerInterface;
use Nicode\FormStudio\Domain\{Diagnostic, Uuid};
use Nicode\FormStudio\Infrastructure\Database\{FormRepository, JobRepository, SubmissionRepository};

/** External effects remain outside a transaction. Expected attempts fence retries. */
final readonly class ActionRetryHandler implements JobHandlerInterface
{
    public function __construct(private FormRepository $forms, private SubmissionRepository $submissions, private JobRepository $jobs, private ActionEngine $engine, private \Closure $authorize) {}
    public function id(): string { return 'action-retry'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['resumable' => true, 'retry' => 'definite_failure_only']; }
    public function validateConfiguration(array $configuration, string $path): array
    {
        $invalid = [new Diagnostic('job.action_retry', $path, 'Expected a response and failed action attempts.')];
        if (!is_int($configuration['form_id'] ?? null) || $configuration['form_id'] < 1 || !is_int($configuration['submission_id'] ?? null) || $configuration['submission_id'] < 1 || !is_array($configuration['attempts'] ?? null) || $configuration['attempts'] === [] || count($configuration['attempts']) > 500) { return $invalid; }
        foreach ($configuration['attempts'] as $uuid => $attempt) { if (!Uuid::valid($uuid) || !is_int($attempt) || $attempt < 1) { return $invalid; } }
        return [];
    }
    public function run(JobLease $job, int $limit): JobProgress
    {
        if ($this->validateConfiguration($job->parameters, '/job') !== []) { throw new \InvalidArgumentException('Invalid action retry.'); }
        $form = $job->parameters['form_id']; $submission = $job->parameters['submission_id'];
        foreach ([[null, 'core.manage'], [$form, 'formstudio.submissions.view'], [$form, 'formstudio.submissions.retry']] as [$scope, $permission]) {
            if (!($this->authorize)($job->creator, $scope, $permission)) { throw new \DomainException('Retry permission unavailable.'); }
        }
        $row = $this->submissions->get($form, $submission);
        if ($row['anonymized_at'] !== null) { throw new \DomainException('Anonymized response.'); }
        $spec = $this->forms->version($form, (int) $row['form_version_id']);
        $payload = json_decode($row['canonical_payload'], true, 512, JSON_THROW_ON_ERROR);
        $this->jobs->renew($job);
        $outcome = $this->engine->execute($submission, new ActionContext($spec, $payload['values'], $row['uuid'], $row['received_at'], $payload['option_labels'] ?? [], locale: $row['locale'], instances: $payload['instances'] ?? null), true, $job->parameters['attempts']);
        if ($outcome['status'] === 'pending') { throw new RetryableJobFailure('action_pending', 30); }
        return new JobProgress([], 1, $outcome['status'] === 'succeeded' ? 0 : 1, complete: true);
    }
}
