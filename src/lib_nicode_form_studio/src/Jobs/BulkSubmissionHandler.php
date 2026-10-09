<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Jobs;

use Nicode\FormStudio\Application\SubmissionAdministration;
use Nicode\FormStudio\Contract\TransactionalJobHandlerInterface;
use Nicode\FormStudio\Domain\{ConcurrentEdit, Diagnostic};
use Nicode\FormStudio\Infrastructure\Database\{Connection, FormRepository, JobRepository, SubmissionMaintenance};
use Nicode\FormStudio\Search\{SearchRequest, SearchScope, SelectedSearch};
use Nicode\FormStudio\Contract\SearchProviderInterface;

final readonly class BulkSubmissionHandler implements TransactionalJobHandlerInterface
{
    public function __construct(private Connection $db, private FormRepository $forms, private JobRepository $jobs, private SearchProviderInterface $search, private SubmissionAdministration $administration, private SubmissionMaintenance $privacy, private \Closure $authorize) {}
    public function id(): string { return 'submission-bulk'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['resumable' => true, 'transactional' => true, 'operations' => ['state', 'anonymize', 'delete']]; }
    public function validateConfiguration(array $configuration, string $path): array
    {
        $operation = $configuration['operation'] ?? '';
        if (!is_int($configuration['form_id'] ?? null) || $configuration['form_id'] < 1 || !is_int($configuration['version_id'] ?? null) || $configuration['version_id'] < 1 || !in_array($operation, ['state', 'anonymize', 'delete'], true)
            || ($operation === 'state' && !in_array($configuration['state'] ?? '', SubmissionAdministration::STATES, true))) { return [new Diagnostic('job.bulk', $path, 'Invalid bulk operation or form version.')]; }
        $query = $configuration['query'] ?? null;
        if (!is_array($query) || !is_array($query['filters'] ?? null) || !is_array($query['fields'] ?? null) || !is_string($query['sort'] ?? 'received_at_desc') || (int) ($query['filters']['form_id'] ?? 0) !== $configuration['form_id']) { return [new Diagnostic('job.bulk_query', $path, 'A form-scoped query is required.')]; }
        try { new SearchRequest($query['filters'], $query['fields'], sort: $query['sort'] ?? 'received_at_desc'); } catch (\InvalidArgumentException) { return [new Diagnostic('job.bulk_query', $path, 'Invalid bulk query.')]; }
        return [];
    }
    public function run(JobLease $job, int $limit): JobProgress
    {
        if ($this->validateConfiguration($job->parameters, '/job') !== [] || $limit < 1 || $limit > 500) { throw new \InvalidArgumentException('Invalid bulk chunk.'); }
        $form = $job->parameters['form_id']; $operation = $job->parameters['operation'];
        foreach ([[null, 'core.manage'], [$form, 'formstudio.submissions.view'], [$form, 'formstudio.submissions.' . ($operation === 'state' ? 'manage' : $operation)]] as [$scope, $permission]) {
            if (!(($this->authorize)($job->creator, $scope, $permission))) { throw new \DomainException('Bulk permission unavailable.'); }
        }
        $schema = $this->forms->version($form, $job->parameters['version_id']); $query = $job->parameters['query'];
        SelectedSearch::assertJob($this->search, $job->parameters);
        $high = isset($job->cursor['high_id']) ? (int) $job->cursor['high_id'] : (int) ($this->db->row('SELECT MAX(id) AS high_id FROM ' . $this->db->table('submissions') . ' WHERE form_id = :form', [':form' => $form])['high_id'] ?? 0);
        $scope = new SearchScope([$form => (bool) ($this->authorize)($job->creator, $form, 'formstudio.submissions.view_sensitive')]);
        $page = $this->search->search(new SearchRequest($query['filters'], $query['fields'], $limit, $job->cursor['search_cursor'] ?? null, $high, $query['sort'] ?? 'received_at_desc'), $scope, $schema);
        $processed = 0; $failed = 0;
        foreach ($page->rows as $row) {
            $this->jobs->renew($job);
            try {
                if ($operation === 'state') { $this->administration->changeState($job->creator, $form, (int) $row['id'], $row['state'], $job->parameters['state']); }
                else { $this->privacy->apply($form, (int) $row['id'], $job->creator, $operation); }
                $processed++;
            } catch (ConcurrentEdit|\OutOfBoundsException) { $failed++; }
        }
        return new JobProgress(['high_id' => $high, 'search_cursor' => $page->nextCursor], $processed, $failed, complete: $page->nextCursor === null);
    }
}
