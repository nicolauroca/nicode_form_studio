<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Infrastructure\Database\{Connection, FormRepository, JobRepository};
use Nicode\FormStudio\Registry\JobHandlerRegistry;
use Nicode\FormStudio\Search\{SearchRequest, SearchScope};
use Nicode\FormStudio\Contract\SearchProviderInterface;

/** Public job boundary: callers cannot choose creators, versions or cleanup objects. */
final readonly class JobAdministration
{
    private const COLUMNS = 'id, uuid, job_type, form_id, creator_id, state, processed, failed, created_at, started_at, finished_at, result_code, expires_at';
    public function __construct(private Connection $db, private FormRepository $forms, private JobRepository $jobs, private JobHandlerRegistry $handlers, private SearchProviderInterface $search, private \Closure $authorize) {}

    public function enqueue(int $actor, int $form, string $type, array $input): int
    {
        if ($form === 0 && $type === 'reindex' && ($input['all_forms'] ?? false) === true) {
            $this->assert($actor, null, 'core.manage'); $this->assert($actor, null, 'formstudio.submissions.reindex');
            return $this->jobs->enqueue('reindex', ['all_forms' => true], $actor);
        }
        return $this->db->transaction(function () use ($actor, $form, $type, $input): int {
            $row = $this->db->row('SELECT state FROM ' . $this->db->table('forms') . ' WHERE id = :id FOR UPDATE', [':id' => $form]);
            if (!$row || $row['state'] === 'deleting') { throw new \DomainException('Form unavailable for jobs.'); }
            return $this->enqueueReady($actor, $form, $type, $input);
        });
    }

    private function enqueueReady(int $actor, int $form, string $type, array $input): int
    {
        $this->assert($actor, null, 'core.manage');
        if (!in_array($type, ['export-csv', 'export-json', 'submission-bulk', 'reindex'], true)) { throw new \InvalidArgumentException('Unsupported administrative job.'); }
        if ($this->forms->get($form)['state'] === 'deleting') { throw new \DomainException('Form deletion is in progress.'); }
        $operation = $input['operation'] ?? '';
        if ($type === 'submission-bulk' && !in_array($operation, ['state', 'anonymize', 'delete'], true)) { throw new \InvalidArgumentException('Invalid bulk operation.'); }
        $permission = match ($type) { 'export-csv', 'export-json' => 'export', 'reindex' => 'reindex', default => $operation === 'state' ? 'manage' : $operation };
        $this->assert($actor, $form, 'formstudio.submissions.' . $permission);
        if ($type === 'submission-bulk') { $this->assert($actor, $form, 'formstudio.submissions.view'); }
        $parameters = ['form_id' => $form];
        if ($type === 'reindex') {
            $parameters += array_intersect_key($input, array_flip(['submission_id', 'received_from', 'received_to']));
            if ($this->handlers->get($type)->validateConfiguration($parameters, '/job') !== []) { throw new \InvalidArgumentException('Invalid reindex selection.'); }
            if (isset($parameters['submission_id']) && $this->db->row('SELECT id FROM ' . $this->db->table('submissions') . ' WHERE id = :id AND form_id = :form', [':id' => $parameters['submission_id'], ':form' => $form]) === null) { throw new \OutOfBoundsException('Submission unavailable for reindex.'); }
        }
        if ($type !== 'reindex') {
            $version = (int) ($this->forms->get($form)['published_version_id'] ?? 0);
            if ($version < 1) { throw new \DomainException('A published schema is required.'); }
            $spec = $this->forms->version($form, $version);
            $query = $input['query'] ?? ['filters' => [], 'fields' => []];
            if (!is_array($query) || array_diff(array_keys($query), ['filters', 'fields', 'sort']) !== [] || !is_array($query['filters'] ?? null) || !is_array($query['fields'] ?? null) || !is_string($query['sort'] ?? 'received_at_desc')) { throw new \InvalidArgumentException('Invalid job query.'); }
            if (isset($query['filters']['form_id']) && (string) $query['filters']['form_id'] !== (string) $form) { throw new \InvalidArgumentException('Query form mismatch.'); }
            $query['filters']['form_id'] = $form;
            $sensitive = (bool) ($this->authorize)($actor, $form, 'formstudio.submissions.view_sensitive');
            $this->search->search(new SearchRequest($query['filters'], $query['fields'], 1, sort: $query['sort'] ?? 'received_at_desc'), new SearchScope([$form => $sensitive]), $spec);
            $parameters += ['version_id' => $version, 'query' => $query, 'search_provider' => ['id' => $this->search->id(), 'version' => $this->search->version()]];
            if (in_array($type, ['export-csv', 'export-json'], true)) {
                $include = $input['include_sensitive'] ?? false;
                if (!is_bool($include) || ($include && !$sensitive)) { throw new \DomainException('Sensitive export unavailable.'); }
                $fields = $input['fields'] ?? [];
                if (!is_array($fields) || !array_is_list($fields) || count($fields) > 500) { throw new \InvalidArgumentException('Invalid export fields.'); }
                $map = array_column($spec->toArray()['fields'], null, 'uuid');
                foreach ($fields as $uuid) {
                    if (!is_string($uuid) || !isset($map[$uuid])) { throw new \InvalidArgumentException('Unknown export field.'); }
                    $field = $map[$uuid];
                    if ($field['type'] === 'password' || !($field['include_export'] ?? !($field['sensitive'] ?? false)) || (($field['sensitive'] ?? false) && !$include)) { throw new \DomainException('Export field excluded by policy.'); }
                }
                $parameters += ['fields' => $fields, 'include_sensitive' => $include];
            } else { $parameters += ['operation' => $operation, 'state' => $input['state'] ?? null]; }
        }
        $handler = $this->handlers->get($type);
        if (($handler->metadata()['available'] ?? true) === false) { throw new \DomainException('Job storage unavailable.'); }
        if ($handler->validateConfiguration($parameters, '/job') !== []) { throw new \InvalidArgumentException('Invalid job configuration.'); }
        return $this->jobs->enqueue($type, $parameters, $actor);
    }

    public function listing(int $actor, int $before = PHP_INT_MAX): array
    {
        $this->assert($actor, null, 'core.manage');
        if ($before < 1) { throw new \InvalidArgumentException('Invalid job cursor.'); }
        $manager = (bool) ($this->authorize)($actor, null, 'formstudio.jobs.manage');
        $rows = $this->db->rows('SELECT ' . self::COLUMNS . ' FROM ' . $this->db->table('jobs') . ' WHERE id < :before' . ($manager ? '' : ' AND creator_id = :actor') . ' ORDER BY id DESC LIMIT 101', $manager ? [':before' => $before] : [':before' => $before, ':actor' => $actor]);
        $more = count($rows) > 100; if ($more) { array_pop($rows); }
        return ['rows' => $rows, 'next_before' => $more ? (int) end($rows)['id'] : null, 'can_run' => $manager];
    }

    public function record(int $actor, int $id): array
    {
        $this->assert($actor, null, 'core.manage');
        $row = $this->db->row('SELECT ' . self::COLUMNS . ' FROM ' . $this->db->table('jobs') . ' WHERE id = :id', [':id' => $id]);
        if (!$row || ((int) $row['creator_id'] !== $actor && !($this->authorize)($actor, null, 'formstudio.jobs.manage'))) { throw new \OutOfBoundsException('Job unavailable.'); }
        return $row;
    }

    public function cancel(int $actor, int $id): bool
    {
        $row = $this->record($actor, $id);
        if (in_array($row['job_type'], ['form-delete', 'package-purge'], true)) { throw new \DomainException('Confirmed deletion cannot be cancelled.'); }
        return $this->jobs->cancel($id);
    }

    private function assert(int $actor, ?int $form, string $permission): void
    {
        if (!($this->authorize)($actor, $form, $permission)) { throw new \DomainException('Job permission unavailable.'); }
    }
}
