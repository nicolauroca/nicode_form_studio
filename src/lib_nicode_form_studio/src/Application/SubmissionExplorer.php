<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Infrastructure\Database\{Connection, FormRepository};
use Nicode\FormStudio\Search\{SearchRequest, SearchScope, SelectedSearch};
use Nicode\FormStudio\Contract\SearchProviderInterface;

/** Builds search scope exclusively from native ACL; never accepts a posted scope or schema. */
final readonly class SubmissionExplorer
{
    public function __construct(private Connection $db, private FormRepository $forms, private SearchProviderInterface $search, private SubmissionReader $reader, private \Closure $authorize, private \Closure $capabilities, private \Nicode\FormStudio\Registry\FieldTypeRegistry $fields) {}

    public function catalog(int $actor): array
    {
        $this->assertAccess($actor);
        $rows = $this->db->rows('SELECT f.id, f.name, f.published_version_id, a.name AS asset_name FROM ' . $this->db->table('forms') . ' f JOIN ' . $this->db->quote('#__assets') . ' a ON a.id = f.asset_id ORDER BY f.id');
        $allowed = ($this->capabilities)($actor, $rows); $result = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (!isset($allowed[$id])) { continue; }
            $result[$id] = ['id' => $id, 'name' => $row['name'], 'published_version_id' => $row['published_version_id'] === null ? null : (int) $row['published_version_id'], 'capabilities' => $allowed[$id]];
        }
        return $result;
    }

    public function page(int $actor, SearchRequest $request, array $columns = []): array
    {
        if (!array_is_list($columns) || count($columns) > 12) { throw new \InvalidArgumentException('Select at most 12 answer columns.'); }
        foreach ($columns as $column) { if (!is_string($column)) { throw new \InvalidArgumentException('Invalid column.'); } }
        $columns = array_values(array_unique($columns));
        $catalog = $this->catalog($actor); $scope = [];
        foreach ($catalog as $id => $form) { $scope[$id] = $form['capabilities']['formstudio.submissions.view_sensitive'] ?? false; }
        $selected = null; $filterFields = []; $schemaVersion = null; $columnFields = [];
        if (isset($request->filters['form_id'])) {
            $id = filter_var($request->filters['form_id'], FILTER_VALIDATE_INT);
            if (!$id || !isset($catalog[$id])) { throw new \DomainException('Submission access denied.'); }
            $schemaVersion = $catalog[$id]['published_version_id'];
            if ($schemaVersion) {
                $schema = $this->forms->version($id, $schemaVersion);
                $elements = array_column($schema->toArray()['elements'], null, 'uuid');
                foreach ($schema->toArray()['fields'] as $field) {
                    if (($field['persist'] ?? true) && $field['type'] !== 'password' && !($field['sensitive'] ?? false)) { $columnFields[$field['uuid']] = ['uuid' => $field['uuid'], 'label' => $field['config']['label'] ?? $field['name']]; }
                    if (!($field['index'] ?? false) || (($field['sensitive'] ?? false) && !$scope[$id])) { continue; }
                    $type = $this->fields->get($field['type'])->indexType();
                    if ($type === null) { continue; }
                    $operators = SelectedSearch::operators($this->search, $type);
                    $repeated = false; $parent = $elements[$field['uuid']]['parent_uuid'] ?? null;
                    while ($parent !== null) {
                        if (($elements[$parent]['type'] ?? null) === 'repeatable-group') { $repeated = true; break; }
                        $parent = $elements[$parent]['parent_uuid'] ?? null;
                    }
                    if ($operators !== []) { $filterFields[] = ['uuid' => $field['uuid'], 'label' => $field['config']['label'] ?? $field['name'], 'index_type' => $type, 'operators' => $operators, 'same_instance' => $repeated && ($this->search->metadata()['same_instance'] ?? false) === true]; }
                }
            }
            if ($request->fieldFilters !== []) {
                $version = $catalog[$id]['published_version_id'];
                if (!$version) { throw new \InvalidArgumentException('Field filters require a published schema.'); }
                $selected = $this->forms->version($id, $version);
            }
        }
        if (array_diff($columns, array_keys($columnFields)) !== []) { throw new \InvalidArgumentException('Unavailable answer column.'); }
        $page = $this->search->search($request, new SearchScope($scope), $selected);
        $rows = []; $answers = $columns === [] ? [] : $this->reader->readBatch((int) $request->filters['form_id'], array_map('intval', array_column($page->rows, 'id')), $actor);
        foreach ($page->rows as $row) {
            $row['form_name'] = $catalog[(int) $row['form_id']]['name']; $row['cells'] = [];
            $labels = [];
            foreach ($columns as $column) { $labels[$column] = $columnFields[$column]['label']; }
            // Historical sensitivity and row order come exclusively from the batch reader.
            $row['cells'] = SubmissionColumns::project($answers[(int) $row['id']] ?? [], $labels);
            $rows[] = $row;
        }
        return ['rows' => $rows, 'next_cursor' => $page->nextCursor, 'forms' => array_values($catalog), 'filter_fields' => $filterFields, 'schema_version' => $schemaVersion, 'column_fields' => array_values($columnFields), 'columns' => $columns, 'sort' => $request->sort, 'sorts' => array_values(array_intersect(SearchRequest::SORTS, $this->search->metadata()['sorts'] ?? []))];
    }

    public function detail(int $actor, int $form, int $submission, bool $revealSensitive = false, array $history = []): array
    {
        $this->assertAccess($actor);
        if (array_diff(array_keys($history), ['actions', 'notes', 'audit']) !== []) { throw new \InvalidArgumentException('Invalid history selection.'); }
        $before = [];
        foreach (['actions', 'notes', 'audit'] as $kind) {
            $value = $history[$kind] ?? PHP_INT_MAX;
            if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1 && (string) (int) $value === $value) { $value = (int) $value; }
            if (!is_int($value) || $value < 1) { throw new \InvalidArgumentException('Invalid history cursor.'); }
            $before[$kind] = $value;
        }
        $record = $this->reader->read($form, $submission, $actor, revealSensitive: $revealSensitive);
        $record['history_before'] = $before;
        $record['id'] = $submission;
        $record['form_name'] = $this->forms->get($form)['name'];
        $record['can_reveal'] = ($this->authorize)($actor, $form, 'formstudio.submissions.view_sensitive');
        $record['can_manage'] = ($this->authorize)($actor, $form, 'formstudio.submissions.manage');
        $record['audit'] = [];
        if (($this->authorize)($actor, $form, 'formstudio.logs.view')) {
            $record['audit'] = $this->db->rows('SELECT id, actor_id, event_type, created_at, safe_metadata FROM ' . $this->db->table('audit_log') . ' WHERE form_id = :form AND submission_uuid = :uuid AND id < :before ORDER BY id DESC LIMIT 101', [':form' => $form, ':uuid' => $record['uuid'], ':before' => $before['audit']]);
        }
        $record['audit_has_more'] = count($record['audit']) > 100;
        if ($record['audit_has_more']) { array_pop($record['audit']); }
        foreach ($record['audit'] as &$event) { $event['safe_metadata'] = \Nicode\FormStudio\Domain\CanonicalJson::encode(AuditLog::details($event['safe_metadata'])); } unset($event);
        $record['files'] = [];
        if ($record['anonymized_at'] === null) {
            foreach ($this->db->rows('SELECT uuid, field_uuid, instance_path, instance_hash, original_name, size_bytes FROM ' . $this->db->table('submission_files') . ' WHERE submission_id = :id ORDER BY id', [':id' => $submission]) as $file) {
                if (\Nicode\FormStudio\Submission\StoredFileAddress::matches($file, $record['values'])) { $record['files'][] = $file; }
            }
        }
        // Explicit columns keep payloads, internal leases and delivery credentials out of the UI.
        $record['actions'] = $this->db->rows('SELECT id, action_uuid, action_type, attempt, state, created_at, started_at, finished_at, result_code, next_retry_at FROM ' . $this->db->table('action_runs') . ' WHERE submission_id = :id AND id < :before ORDER BY id DESC LIMIT 101', [':id' => $submission, ':before' => $before['actions']]);
        $record['actions_has_more'] = count($record['actions']) > 100;
        if ($record['actions_has_more']) { array_pop($record['actions']); }
        $record['notes'] = $this->db->rows('SELECT id, created_by, created_at, body FROM ' . $this->db->table('submission_notes') . ' WHERE submission_id = :id AND id < :before ORDER BY id DESC LIMIT 101', [':id' => $submission, ':before' => $before['notes']]);
        $record['notes_has_more'] = count($record['notes']) > 100;
        if ($record['notes_has_more']) { array_pop($record['notes']); }
        foreach (['actions', 'notes', 'audit'] as $kind) { $record[$kind . '_next_before'] = $record[$kind . '_has_more'] ? (int) $record[$kind][array_key_last($record[$kind])]['id'] : null; }
        return $record;
    }

    private function assertAccess(int $actor): void
    {
        if (!(($this->authorize)($actor, null, 'core.manage'))) { throw new \DomainException('Administrator access denied.'); }
    }
}
