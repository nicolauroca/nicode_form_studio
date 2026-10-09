<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Infrastructure\Database;

use Nicode\FormStudio\Compiler\CompilationException;
use Nicode\FormStudio\Compiler\FormCompiler;
use Nicode\FormStudio\Domain\CanonicalJson;
use Nicode\FormStudio\Domain\ConcurrentEdit;
use Nicode\FormStudio\Domain\FormSpec;
use Nicode\FormStudio\Domain\Uuid;

/** Relational draft persistence. Public authorization belongs to application services. */
final readonly class FormRepository
{
    private const CHILDREN = ['rule_effects', 'rule_conditions', 'rules', 'actions', 'field_options', 'fields', 'elements', 'translations'];
    public function __construct(private Connection $db, private FormCompiler $compiler, private string $defaultPersistence = 'full')
    {
        if (!in_array($defaultPersistence, ['full', 'metadata', 'none'], true)) { throw new \InvalidArgumentException('Invalid default persistence mode.'); }
    }

    public function create(string $name, string $alias, int $actor): int
    {
        return $this->db->transaction(function () use ($name, $alias, $actor): int {
            $state = new PurgeState($this->db); $state->lockSchema(); $state->assertWritable();
            return $this->createWritable($name, $alias, $actor);
        });
    }

    private function createWritable(string $name, string $alias, int $actor): int
    {
        $this->validateIdentity($name, $alias);
        $now = gmdate('Y-m-d H:i:s');
        return $this->db->insert('forms', [
            'uuid' => Uuid::create(), 'name' => $name, 'alias' => $alias, 'state' => 'draft', 'access' => 1, 'language' => '*', 'asset_id' => null,
            'created_at' => $now, 'created_by' => $actor, 'modified_at' => $now, 'modified_by' => $actor,
            'publish_up' => null, 'publish_down' => null, 'draft_revision' => 0, 'published_version_id' => null,
            'params' => CanonicalJson::encode(['schema_version' => '1.0', 'persistence' => ['mode' => $this->defaultPersistence]]),
        ]);
    }

    public function get(int $id): array
    {
        return $this->db->row('SELECT * FROM ' . $this->db->table('forms') . ' WHERE id = :id', [':id' => $id]) ?? throw new \OutOfBoundsException('Form not found.');
    }

    public function draft(int $id): array
    {
        $form = $this->get($id);
        if ($form['state'] === 'deleting') { throw new \DomainException('Form deletion is in progress.'); }
        $draft = json_decode($form['params'], true, 512, JSON_THROW_ON_ERROR);
        $draft['uuid'] = $form['uuid']; $draft['name'] = $form['name'];
        $draft['elements'] = $this->jsonRows('elements', 'properties', $id, 'ordering, id');
        $draft['fields'] = $this->jsonRows('fields', 'config', $id, 'id');
        $draft['rules'] = [];
        // Bounded number of bulk queries, independent of field and rule count.
        $conditions = [];
        foreach ($this->db->rows('SELECT rule_uuid, definition FROM ' . $this->db->table('rule_conditions') . ' WHERE form_id = :id', [':id' => $id]) as $row) {
            $conditions[$row['rule_uuid']] = json_decode($row['definition'], true, 512, JSON_THROW_ON_ERROR);
        }
        $effects = [];
        foreach ($this->db->rows('SELECT rule_uuid, definition FROM ' . $this->db->table('rule_effects') . ' WHERE form_id = :id ORDER BY ordering, id', [':id' => $id]) as $row) {
            $effects[$row['rule_uuid']][] = json_decode($row['definition'], true, 512, JSON_THROW_ON_ERROR);
        }
        foreach ($this->db->rows('SELECT uuid, enabled, priority FROM ' . $this->db->table('rules') . ' WHERE form_id = :id ORDER BY priority, uuid', [':id' => $id]) as $rule) {
            $draft['rules'][] = ['uuid' => $rule['uuid'], 'enabled' => (bool) $rule['enabled'], 'priority' => (int) $rule['priority'], 'when' => $conditions[$rule['uuid']], 'effects' => $effects[$rule['uuid']] ?? []];
        }
        $draft['actions'] = $this->jsonRows('actions', 'config', $id, 'ordering, id');
        return $draft;
    }

    public function saveDraft(int $id, int $expectedRevision, array $draft, int $actor): int
    {
        // Drafts may be semantically incomplete; collection shape must still be safe to persist.
        foreach (['elements', 'fields', 'rules', 'actions'] as $key) {
            if (!is_array($draft[$key] ?? null) || !array_is_list($draft[$key])) { throw new \InvalidArgumentException('Invalid draft collections.'); }
        }
        if ((new \Nicode\FormStudio\Compiler\StructureValidator())->validate($draft) !== []) { throw new \InvalidArgumentException('Invalid draft structure.'); }
        return $this->db->transaction(function () use ($id, $expectedRevision, $draft, $actor): int {
            $form = $this->get($id);
            if (($draft['uuid'] ?? null) !== $form['uuid'] || !is_string($draft['name'] ?? null) || trim($draft['name']) === '') { throw new \InvalidArgumentException('Draft identity mismatch or missing name.'); }
            $this->validateIdentity($draft['name'], $form['alias']);
            $params = array_diff_key($draft, array_flip(['uuid', 'name', 'elements', 'fields', 'rules', 'actions']));
            $changed = $this->db->execute('UPDATE ' . $this->db->table('forms') . ' SET name = :name, params = :params, draft_revision = draft_revision + 1, modified_at = :now, modified_by = :actor WHERE id = :id AND draft_revision = :revision AND state <> :deleting', [
                ':name' => $draft['name'], ':params' => CanonicalJson::encode($params), ':now' => gmdate('Y-m-d H:i:s'), ':actor' => $actor, ':id' => $id, ':deleting' => 'deleting', ':revision' => $expectedRevision,
            ]);
            if ($changed !== 1) { throw new ConcurrentEdit(); }
            foreach (self::CHILDREN as $table) { $this->db->execute('DELETE FROM ' . $this->db->table($table) . ' WHERE form_id = :id', [':id' => $id]); }
            foreach ($draft['elements'] as $i => $element) {
                $this->db->insert('elements', ['uuid' => $element['uuid'], 'form_id' => $id, 'parent_uuid' => $element['parent_uuid'] ?? null, 'element_type' => $element['type'], 'ordering' => $i, 'properties' => CanonicalJson::encode($element)]);
            }
            foreach ($draft['fields'] as $field) {
                $this->db->insert('fields', ['uuid' => $field['uuid'], 'form_id' => $id, 'machine_name' => $field['name'], 'field_type' => $field['type'], 'logical_type' => $field['logical_type'] ?? 'provider', 'config' => CanonicalJson::encode($field), 'persist_value' => (int) ($field['persist'] ?? true), 'searchable' => (int) ($field['index'] ?? false), 'filterable' => (int) ($field['index'] ?? false), 'sortable' => (int) ($field['sortable'] ?? false), 'sensitive' => (int) ($field['sensitive'] ?? false), 'include_email' => (int) ($field['include_email'] ?? !($field['sensitive'] ?? false)), 'include_export' => (int) ($field['include_export'] ?? !($field['sensitive'] ?? false))]);
                foreach ($field['options'] ?? [] as $i => $option) {
                    $this->db->insert('field_options', ['uuid' => $option['uuid'] ?? Uuid::create(), 'form_id' => $id, 'field_uuid' => $field['uuid'], 'option_value' => $option['value'], 'label' => $option['label'], 'ordering' => $i, 'enabled' => (int) ($option['enabled'] ?? true), 'metadata' => CanonicalJson::encode($option)]);
                }
            }
            foreach ($draft['rules'] as $rule) {
                $this->db->insert('rules', ['uuid' => $rule['uuid'], 'form_id' => $id, 'enabled' => (int) ($rule['enabled'] ?? true), 'priority' => $rule['priority'] ?? 0]);
                $this->db->insert('rule_conditions', ['form_id' => $id, 'rule_uuid' => $rule['uuid'], 'definition' => CanonicalJson::encode($rule['when'])]);
                foreach ($rule['effects'] as $i => $effect) { $this->db->insert('rule_effects', ['form_id' => $id, 'rule_uuid' => $rule['uuid'], 'ordering' => $i, 'definition' => CanonicalJson::encode($effect)]); }
            }
            foreach ($draft['actions'] as $i => $action) {
                $this->db->insert('actions', ['uuid' => $action['uuid'], 'form_id' => $id, 'action_type' => $action['type'], 'enabled' => (int) ($action['enabled'] ?? true), 'ordering' => $i, 'condition_spec' => isset($action['condition']) ? CanonicalJson::encode($action['condition']) : null, 'config' => CanonicalJson::encode($action), 'failure_policy' => $action['failure_policy'] ?? 'non_blocking', 'retry_policy' => CanonicalJson::encode($action['retry'] ?? [])]);
            }
            return $expectedRevision + 1;
        });
    }

    public function publish(int $id, int $expectedRevision, int $actor, string $comment = '', ?\Closure $beforeActivate = null): int
    {
        return $this->db->transaction(function () use ($id, $expectedRevision, $actor, $comment, $beforeActivate): int {
            $form = $this->db->row('SELECT * FROM ' . $this->db->table('forms') . ' WHERE id = :id FOR UPDATE', [':id' => $id]);
            if (!$form || (int) $form['draft_revision'] !== $expectedRevision) { throw new ConcurrentEdit(); }
            if ($form['state'] === 'deleting') { throw new \DomainException('Form deletion is irreversible.'); }
            $result = $this->compiler->compile($this->draft($id));
            if (!$result->successful()) { throw new CompilationException($result->diagnostics); }
            if ($beforeActivate !== null) { $beforeActivate($result->spec, $result->diagnostics); }
            $previous = $this->db->row('SELECT MAX(revision) AS revision FROM ' . $this->db->table('form_versions') . ' WHERE form_id = :id', [':id' => $id]);
            $version = $this->db->insert('form_versions', ['form_id' => $id, 'revision' => (int) ($previous['revision'] ?? 0) + 1, 'schema_version' => '1.0', 'spec' => $result->spec->json, 'hash' => $result->spec->hash, 'published_at' => gmdate('Y-m-d H:i:s'), 'published_by' => $actor, 'comment' => $comment, 'revoked_at' => null]);
            (new VersionFieldPolicy($this->db))->rebuild($id, $version);
            // Publishing consumes the optimistic edit revision, preventing two publishers
            // with the same editor token from creating competing activations.
            $this->db->execute('UPDATE ' . $this->db->table('forms') . ' SET published_version_id = :version, state = :state, draft_revision = draft_revision + 1, modified_at = :now, modified_by = :actor WHERE id = :id', [':version' => $version, ':state' => 'published', ':id' => $id, ':now' => gmdate('Y-m-d H:i:s'), ':actor' => $actor]);
            if ($form['published_version_id'] !== null && \Nicode\FormStudio\Search\HistoricalIndexPolicy::signature($this->version($id, (int) $form['published_version_id'])) !== \Nicode\FormStudio\Search\HistoricalIndexPolicy::signature($result->spec)) {
                $this->db->execute('UPDATE ' . $this->db->table('submissions') . ' SET index_pending = 1 WHERE form_id = :form AND anonymized_at IS NULL', [':form' => $id]);
                if ($this->db->row('SELECT id FROM ' . $this->db->table('submissions') . ' WHERE form_id = :form AND index_pending = 1 LIMIT 1', [':form' => $id]) !== null) { (new JobRepository($this->db))->enqueue('reindex', ['form_id' => $id], $actor); }
            }
            return $version;
        });
    }

    /** Deactivation preserves every historical snapshot and response. */
    public function deactivate(int $id, int $expectedRevision, int $actor, string $state): int
    {
        if (!in_array($state, ['unpublished', 'archived', 'trashed'], true)) { throw new \InvalidArgumentException('Invalid inactive form state.'); }
        $changed = $this->db->execute('UPDATE ' . $this->db->table('forms') . ' SET state = :state, draft_revision = draft_revision + 1, modified_at = :now, modified_by = :actor WHERE id = :id AND draft_revision = :revision AND state <> :deleting', [':state' => $state, ':now' => gmdate('Y-m-d H:i:s'), ':actor' => $actor, ':id' => $id, ':deleting' => 'deleting', ':revision' => $expectedRevision]);
        if ($changed !== 1) { throw new ConcurrentEdit(); }
        return $expectedRevision + 1;
    }

    public function history(int $formId, int $beforeRevision = PHP_INT_MAX, int $limit = 50, bool $includeCounts = false): array
    {
        if ($limit < 1 || $limit > 100 || $beforeRevision < 1) { throw new \InvalidArgumentException('Invalid history page.'); }
        $rows = $this->db->rows('SELECT v.id, v.revision, v.schema_version, v.hash, v.published_at, v.published_by, v.comment, v.revoked_at, CASE WHEN v.revoked_at IS NOT NULL THEN \'revoked\' WHEN f.published_version_id = v.id AND f.state = \'published\' THEN \'active\' ELSE \'historical\' END AS version_state FROM ' . $this->db->table('form_versions') . ' v JOIN ' . $this->db->table('forms') . ' f ON f.id = v.form_id WHERE v.form_id = :form AND v.revision < :before ORDER BY v.revision DESC LIMIT ' . $limit, [':form' => $formId, ':before' => $beforeRevision]);
        if ($includeCounts && $rows !== []) {
            $params = [':form' => $formId]; $keys = [];
            foreach ($rows as $i => $row) { $keys[] = ':version' . $i; $params[':version' . $i] = (int) $row['id']; }
            $counts = [];
            foreach ($this->db->rows('SELECT form_version_id, COUNT(*) AS total FROM ' . $this->db->table('submissions') . ' WHERE form_id = :form AND form_version_id IN (' . implode(', ', $keys) . ') GROUP BY form_version_id', $params) as $count) { $counts[(int) $count['form_version_id']] = (int) $count['total']; }
            foreach ($rows as &$row) { $row['submission_count'] = $counts[(int) $row['id']] ?? 0; } unset($row);
        }
        return $rows;
    }

    /** Publication controls are live form metadata and consume the same editor revision. */
    public function settings(int $id, int $expectedRevision, array $settings, int $actor): int
    {
        $required = ['name', 'alias', 'access', 'language', 'publish_up', 'publish_down'];
        if (array_diff(array_keys($settings), $required) !== [] || array_diff($required, array_keys($settings)) !== []) { throw new \InvalidArgumentException('Invalid form settings.'); }
        if (!is_string($settings['name']) || !is_string($settings['alias'])) { throw new \InvalidArgumentException('Invalid form identity.'); }
        $this->validateIdentity($settings['name'], $settings['alias']);
        if (!is_int($settings['access']) || $settings['access'] < 1 || !is_string($settings['language']) || preg_match('/^(?:\*|[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})*)$/D', $settings['language']) !== 1 || strlen($settings['language']) > 32) { throw new \InvalidArgumentException('Invalid access or language.'); }
        foreach (['publish_up', 'publish_down'] as $key) {
            if ($settings[$key] === null) { continue; }
            $date = is_string($settings[$key]) ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $settings[$key], new \DateTimeZone('UTC')) : false;
            if (!$date || $date->format('Y-m-d H:i:s') !== $settings[$key] || $date->format('Y') < '1000') { throw new \InvalidArgumentException('Invalid publication time.'); }
        }
        if ($settings['publish_up'] !== null && $settings['publish_down'] !== null && $settings['publish_down'] <= $settings['publish_up']) { throw new \InvalidArgumentException('Publication end must follow start.'); }
        $parameters = [':id' => $id, ':deleting' => 'deleting', ':revision' => $expectedRevision, ':actor' => $actor, ':now' => gmdate('Y-m-d H:i:s')]; $assignments = [];
        foreach ($required as $key) { $assignments[] = $this->db->quote($key) . ' = :' . $key; $parameters[':' . $key] = $settings[$key]; }
        $changed = $this->db->execute('UPDATE ' . $this->db->table('forms') . ' SET ' . implode(', ', $assignments) . ', draft_revision = draft_revision + 1, modified_at = :now, modified_by = :actor WHERE id = :id AND draft_revision = :revision AND state <> :deleting', $parameters);
        if ($changed !== 1) { throw new ConcurrentEdit(); }
        return $expectedRevision + 1;
    }

    private function validateIdentity(string $name, string $alias): void
    {
        if (trim($name) === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 255 || strlen($alias) > 255 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $alias) !== 1) { throw new \InvalidArgumentException('A name and valid alias are required.'); }
    }

    public function version(int $formId, int $versionId): FormSpec
    {
        $row = $this->db->row('SELECT spec, hash FROM ' . $this->db->table('form_versions') . ' WHERE id = :version AND form_id = :form', [':version' => $versionId, ':form' => $formId]);
        if (!$row) { throw new \OutOfBoundsException('Form version not found.'); }
        $spec = new FormSpec(json_decode($row['spec'], true, 512, JSON_THROW_ON_ERROR));
        if (!hash_equals($row['hash'], $spec->hash)) { throw new \DomainException('Snapshot integrity check failed.'); }
        return $spec;
    }

    public function restore(int $formId, int $versionId, int $expectedRevision, int $actor): int
    {
        return $this->saveDraft($formId, $expectedRevision, $this->version($formId, $versionId)->toArray(), $actor);
    }

    /** Bounded bulk lookup for export/detail batches; no query per response. */
    public function versions(int $formId, array $versionIds): array
    {
        $versionIds = array_values(array_unique($versionIds));
        if ($versionIds === []) { return []; }
        if (count($versionIds) > 500) { throw new \InvalidArgumentException('Version batch too large.'); }
        $parameters = [':form' => $formId]; $keys = [];
        foreach ($versionIds as $i => $id) { if (!is_int($id) || $id < 1) { throw new \InvalidArgumentException('Invalid version ID.'); } $keys[] = ':v' . $i; $parameters[':v' . $i] = $id; }
        $rows = $this->db->rows('SELECT id, spec, hash FROM ' . $this->db->table('form_versions') . ' WHERE form_id = :form AND id IN (' . implode(', ', $keys) . ')', $parameters); $result = [];
        foreach ($rows as $row) {
            $spec = new FormSpec(json_decode($row['spec'], true, 512, JSON_THROW_ON_ERROR));
            if (!hash_equals($row['hash'], $spec->hash)) { throw new \DomainException('Snapshot integrity check failed.'); }
            $result[(int) $row['id']] = $spec;
        }
        if (count($result) !== count($versionIds)) { throw new \OutOfBoundsException('Form version missing from batch.'); }
        return $result;
    }

    private function jsonRows(string $table, string $column, int $id, string $order): array
    {
        return array_map(static fn (array $row): array => json_decode($row[$column], true, 512, JSON_THROW_ON_ERROR), $this->db->rows('SELECT ' . $this->db->quote($column) . ' FROM ' . $this->db->table($table) . ' WHERE form_id = :id ORDER BY ' . $order, [':id' => $id]));
    }
}
