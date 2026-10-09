<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Jobs;

use Nicode\FormStudio\Application\SubmissionReader;
use Nicode\FormStudio\Contract\JobHandlerInterface;
use Nicode\FormStudio\Domain\CanonicalJson;
use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Domain\Uuid;
use Nicode\FormStudio\Export\ExportWorkspace;
use Nicode\FormStudio\Infrastructure\Database\Connection;
use Nicode\FormStudio\Infrastructure\Database\FormRepository;
use Nicode\FormStudio\Infrastructure\Database\JobRepository;

final readonly class ExportHandler implements JobHandlerInterface
{
    public function __construct(private Connection $db, private FormRepository $forms, private SubmissionReader $reader, private JobRepository $jobs, private ExportWorkspace $workspace, private \Closure $authorize, private ?\Nicode\FormStudio\Contract\SearchProviderInterface $search = null, private ?\Nicode\FormStudio\Contract\LifecycleEventsInterface $events = null, private string $format = 'csv')
    {
        if (!in_array($format, ['csv', 'json'], true)) { throw new \InvalidArgumentException('Unsupported export format.'); }
    }
    public function id(): string { return 'export-' . $this->format; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['resumable' => true, 'idempotent' => true, 'format' => $this->format]; }
    public function validateConfiguration(array $configuration, string $path): array
    {
        if (!is_int($configuration['form_id'] ?? null) || $configuration['form_id'] < 1 || !is_int($configuration['version_id'] ?? null) || $configuration['version_id'] < 1 || !is_array($configuration['fields'] ?? null) || !array_is_list($configuration['fields']) || !is_bool($configuration['include_sensitive'] ?? false)) { return [new Diagnostic('job.export', $path, 'Expected form, version, ordered fields and sensitive policy.')]; }
        foreach ($configuration['fields'] as $uuid) { if (!Uuid::valid($uuid)) { return [new Diagnostic('job.export_field', $path, 'Invalid export field.')]; } }
        if (count(array_unique($configuration['fields'])) !== count($configuration['fields'])) { return [new Diagnostic('job.export_duplicate', $path, 'Duplicate export field.')]; }
        if (isset($configuration['query'])) {
            $query = $configuration['query'];
            if (!is_array($query) || !is_array($query['filters'] ?? null) || !is_array($query['fields'] ?? null) || !is_string($query['sort'] ?? 'received_at_desc') || (int) ($query['filters']['form_id'] ?? 0) !== $configuration['form_id']) { return [new Diagnostic('job.export_query', $path, 'Expected a query scoped to the export form.')]; }
            try { new \Nicode\FormStudio\Search\SearchRequest($query['filters'], $query['fields'], sort: $query['sort'] ?? 'received_at_desc'); }
            catch (\InvalidArgumentException) { return [new Diagnostic('job.export_query', $path, 'Invalid export query.')]; }
        }
        return [];
    }
    public function run(JobLease $job, int $limit): JobProgress
    {
        if ($this->validateConfiguration($job->parameters, '/job') !== [] || $limit < 1 || $limit > 500) { throw new \InvalidArgumentException('Invalid export chunk.'); }
        $form = $job->parameters['form_id']; $sensitive = $job->parameters['include_sensitive'] ?? false;
        if (!(($this->authorize)($job->creator, $form, 'formstudio.submissions.export')) || ($sensitive && !(($this->authorize)($job->creator, $form, 'formstudio.submissions.view_sensitive')))) { throw new \DomainException('Export permission unavailable.'); }
        $spec = $this->forms->version($form, $job->parameters['version_id']); $fieldMap = array_column($spec->toArray()['fields'], null, 'uuid');
        $header = ['reference', 'received_at', 'state']; $fields = [];
        foreach ($job->parameters['fields'] as $uuid) {
            $field = $fieldMap[$uuid] ?? throw new \DomainException('Export field unavailable.');
            if ($field['type'] === 'password' || !($field['include_export'] ?? !($field['sensitive'] ?? false)) || (($field['sensitive'] ?? false) && !$sensitive)) { throw new \DomainException('Export field excluded by policy.'); }
            $fields[] = $uuid; $header[] = $field['config']['label'] ?? $field['name'];
        }
        $last = (int) ($job->cursor['last_id'] ?? 0); $offset = (int) ($job->cursor['bytes'] ?? 0);
        $high = isset($job->cursor['high_id']) ? (int) $job->cursor['high_id'] : (int) ($this->db->row('SELECT MAX(id) AS high_id FROM ' . $this->db->table('submissions') . ' WHERE form_id = :form', [':form' => $form])['high_id'] ?? 0);
        $searchCursor = null; $searchComplete = false;
        if (isset($job->parameters['query'])) {
            if ($this->search === null) { throw new \DomainException('Filtered export provider unavailable.'); }
            \Nicode\FormStudio\Search\SelectedSearch::assertJob($this->search, $job->parameters);
            $query = $job->parameters['query'];
            $scope = new \Nicode\FormStudio\Search\SearchScope([$form => (bool) ($this->authorize)($job->creator, $form, 'formstudio.submissions.view_sensitive')]);
            $page = $this->search->search(new \Nicode\FormStudio\Search\SearchRequest($query['filters'], $query['fields'], $limit, $job->cursor['search_cursor'] ?? null, $high, $query['sort'] ?? 'received_at_desc'), $scope, $spec);
            $ids = array_map('intval', array_column($page->rows, 'id')); $searchCursor = $page->nextCursor; $searchComplete = $searchCursor === null;
        } else {
            $ids = array_map('intval', array_column($this->db->rows('SELECT id FROM ' . $this->db->table('submissions') . ' WHERE form_id = :form AND id > :last AND id <= :high ORDER BY id LIMIT ' . $limit, [':form' => $form, ':last' => $last, ':high' => $high]), 'id'));
        }
        $rows = [];
        $records = $this->reader->readBatch($form, $ids, $job->creator, 'export', $sensitive);
        foreach ($ids as $id) {
            $record = $records[$id] ?? null; if ($record === null) { continue; }
            $values = [$record['uuid'], $record['received_at'], $record['state']];
            $columns = \Nicode\FormStudio\Export\FieldColumns::project($record['values'], $fields);
            if ($this->format === 'json') {
                $rows[] = ['reference' => $record['uuid'], 'form_version_id' => $record['form_version_id'], 'received_at' => $record['received_at'], 'state' => $record['state'], 'values' => $columns];
                continue;
            }
            foreach ($fields as $uuid) { $values[] = $columns[$uuid]; }
            $rows[] = $values;
        }
        if ($ids !== []) { $last = end($ids); }
        $complete = isset($job->parameters['query']) ? $searchComplete : count($ids) < $limit || $last >= $high;
        $eventContext = ['form_id' => $form, 'version_id' => $job->parameters['version_id'], 'job_id' => $job->id, 'format' => $this->format . '-chunk', 'processed' => $job->processed];
        $this->events?->emit('BeforeExport', $eventContext);
        $offset = $this->format === 'json'
            ? $this->workspace->appendJson($job->uuid, $offset, $rows, $complete, fn () => $this->jobs->renew($job))
            : $this->workspace->append($job->uuid, $offset, $header, $rows, fn () => $this->jobs->renew($job));
        if ($complete) { $this->db->insert('audit_log', ['correlation_id' => $job->uuid, 'actor_id' => $job->creator, 'event_type' => 'submission.export', 'form_id' => $form, 'submission_uuid' => null, 'created_at' => gmdate('Y-m-d H:i:s'), 'safe_metadata' => CanonicalJson::encode(['rows' => $job->processed + count($rows), 'sensitive' => $sensitive])]); }
        $this->events?->emit('AfterExport', array_replace($eventContext, ['processed' => $job->processed + count($rows), 'complete' => $complete]));
        return new JobProgress(['last_id' => $last, 'high_id' => $high, 'bytes' => $offset, 'search_cursor' => $searchCursor], count($rows), complete: $complete, artifactKey: $complete ? $job->uuid : null);
    }
}
