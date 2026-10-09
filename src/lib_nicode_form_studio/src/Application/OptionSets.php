<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Domain\{CanonicalJson, ConcurrentEdit, Uuid};
use Nicode\FormStudio\Infrastructure\Database\Connection;

/** Shared option resources, immutable revisions and explicit dependency bindings. */
final readonly class OptionSets
{
    public function __construct(private Connection $db, private \Closure $authorize) {}
    public function listing(int $actor, int $before = PHP_INT_MAX): array
    {
        $this->access($actor); if ($before < 1) { throw new \InvalidArgumentException('Invalid resource cursor.'); }
        $rows = $this->db->rows('SELECT id, uuid, name, revision, modified_at FROM ' . $this->db->table('option_sets') . ' WHERE id < :before ORDER BY id DESC LIMIT 101', [':before' => $before]);
        $more = count($rows) > 100; if ($more) { array_pop($rows); }
        return ['rows' => $rows, 'next_before' => $more ? (int) end($rows)['id'] : null, 'can_edit' => (bool) ($this->authorize)($actor, null, 'formstudio.resources.manage')];
    }
    public function create(int $actor, string $name): int
    {
        $this->access($actor, true); $this->name($name);
        return $this->db->transaction(function () use ($actor, $name): int {
            $id = $this->db->insert('option_sets', ['uuid' => Uuid::create(), 'name' => $name, 'revision' => 0, 'modified_at' => gmdate('Y-m-d H:i:s'), 'modified_by' => $actor]);
            $this->audit($actor, 'resource.option_set.create', $id, 0); return $id;
        });
    }
    public function history(int $actor, int $id, int $before = PHP_INT_MAX): array
    {
        $this->access($actor); if ($before < 1) { throw new \InvalidArgumentException('Invalid resource version cursor.'); }
        $rows = $this->db->rows('SELECT revision, created_at, created_by FROM ' . $this->db->table('option_set_versions') . ' WHERE option_set_id = :id AND revision < :before ORDER BY revision DESC LIMIT 101', [':id' => $id, ':before' => $before]);
        $more = count($rows) > 100; if ($more) { array_pop($rows); }
        return ['rows' => $rows, 'next_before' => $more ? (int) end($rows)['revision'] : null];
    }
    public function read(int $actor, int $id, ?int $revision = null): array
    {
        $this->access($actor);
        $set = $this->db->row('SELECT id, uuid, name, revision, modified_at FROM ' . $this->db->table('option_sets') . ' WHERE id = :id', [':id' => $id]) ?? throw new \OutOfBoundsException('Option set unavailable.');
        if ($revision === null && (int) $set['revision'] === 0) { return ['resource' => $set, 'snapshot' => ['uuid' => $set['uuid'], 'name' => $set['name'], 'revision' => 0, 'options' => []]]; }
        $revision ??= (int) $set['revision'];
        $row = $this->db->row('SELECT snapshot, hash FROM ' . $this->db->table('option_set_versions') . ' WHERE option_set_id = :id AND revision = :revision', [':id' => $id, ':revision' => $revision]) ?? throw new \OutOfBoundsException('Option set version unavailable.');
        if (!hash_equals($row['hash'], hash('sha256', $row['snapshot']))) { throw new \DomainException('Option set snapshot integrity failed.'); }
        $snapshot = json_decode($row['snapshot'], true, 128, JSON_THROW_ON_ERROR);
        if ($snapshot['uuid'] !== $set['uuid'] || $snapshot['revision'] !== $revision) { throw new \DomainException('Option set snapshot ownership failed.'); }
        return ['resource' => $set, 'snapshot' => $snapshot];
    }
    public function save(int $actor, int $id, int $expected, string $name, array $options): int
    {
        $this->access($actor, true); $this->name($name); $options = $this->options($options);
        if ($expected < 0) { throw new \InvalidArgumentException('Invalid resource revision.'); }
        return $this->db->transaction(function () use ($actor, $id, $expected, $name, $options): int {
            $row = $this->db->row('SELECT uuid, revision FROM ' . $this->db->table('option_sets') . ' WHERE id = :id FOR UPDATE', [':id' => $id]) ?? throw new \OutOfBoundsException('Option set unavailable.');
            if ((int) $row['revision'] !== $expected) { throw new ConcurrentEdit('Option set changed.'); }
            $next = $expected + 1; $now = gmdate('Y-m-d H:i:s');
            $snapshot = CanonicalJson::encode(['uuid' => $row['uuid'], 'name' => $name, 'revision' => $next, 'options' => $options]);
            $this->db->insert('option_set_versions', ['option_set_id' => $id, 'revision' => $next, 'snapshot' => $snapshot, 'hash' => hash('sha256', $snapshot), 'created_at' => $now, 'created_by' => $actor]);
            $this->db->execute('DELETE FROM ' . $this->db->table('option_set_items') . ' WHERE option_set_id = :id', [':id' => $id]);
            $items = [];
            foreach ($options as $i => $option) { $items[] = ['option_set_id' => $id, 'uuid' => $option['uuid'], 'option_value' => $option['value'], 'label' => $option['label'], 'ordering' => $i, 'enabled' => (int) $option['enabled'], 'metadata' => CanonicalJson::encode(['default' => $option['default'], 'when' => $option['when'], 'metadata' => $option['metadata']])]; }
            $this->db->insertMany('option_set_items', $items);
            $this->db->execute('UPDATE ' . $this->db->table('option_sets') . ' SET name = :name, revision = :revision, modified_at = :now, modified_by = :actor WHERE id = :id', [':name' => $name, ':revision' => $next, ':now' => $now, ':actor' => $actor, ':id' => $id]);
            $this->audit($actor, 'resource.option_set.save', $id, $next); return $next;
        });
    }
    /** Parameter names are portable; bind them to concrete field UUIDs per form. */
    public function source(int $actor, int $id, int $revision, array $bindings = []): array
    {
        $snapshot = $this->read($actor, $id, $revision)['snapshot']; $options = []; $dependencies = [];
        foreach ($bindings as $parameter => $uuid) { if (!is_string($parameter) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $parameter) !== 1 || !Uuid::valid($uuid)) { throw new \InvalidArgumentException('Invalid option dependency binding.'); } }
        foreach ($snapshot['options'] as $option) {
            $when = [];
            foreach ($option['when'] as $parameter => $value) {
                $uuid = $bindings[$parameter] ?? throw new \InvalidArgumentException('Missing option dependency binding.');
                if (array_key_exists($uuid, $when)) { throw new \InvalidArgumentException('Ambiguous option dependency binding.'); }
                $when[$uuid] = $value; $dependencies[$uuid] = true;
            }
            $option['when'] = $when; $options[] = $option;
        }
        return ['type' => 'option_set', 'dependencies' => array_keys($dependencies), 'ttl' => 0, 'config' => ['resource_uuid' => $snapshot['uuid'], 'revision' => $revision, 'resource_hash' => hash('sha256', CanonicalJson::encode($snapshot)), 'options' => $options]];
    }
    private function options(array $options): array
    {
        if (!array_is_list($options)) { throw new \InvalidArgumentException('Expected ordered options.'); }
        $values = []; $uuids = [];
        foreach ($options as &$option) {
            if (!is_array($option) || array_diff(array_keys($option), ['uuid', 'value', 'label', 'enabled', 'default', 'when', 'metadata']) !== []) { throw new \InvalidArgumentException('Unknown option properties.'); }
            $option += ['uuid' => Uuid::create(), 'enabled' => true, 'default' => false, 'when' => [], 'metadata' => []];
            if (!Uuid::valid($option['uuid']) || !is_string($option['value'] ?? null) || $option['value'] === '' || mb_strlen($option['value'], 'UTF-8') > 255 || !is_string($option['label'] ?? null) || mb_strlen($option['label'], 'UTF-8') > 16384 || !mb_check_encoding($option['value'] . $option['label'], 'UTF-8') || !is_bool($option['enabled']) || !is_bool($option['default']) || !is_array($option['when']) || !is_array($option['metadata'])) { throw new \InvalidArgumentException('Invalid option value, label or policy.'); }
            if (isset($values[$option['value']]) || isset($uuids[$option['uuid']])) { throw new \InvalidArgumentException('Duplicate option identity.'); }
            $values[$option['value']] = $uuids[$option['uuid']] = true;
            foreach ($option['when'] as $parameter => $value) { if (!is_string($parameter) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $parameter) !== 1 || (!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null)) { throw new \InvalidArgumentException('Invalid dependency condition.'); } }
            CanonicalJson::encode($option['metadata']);
        }
        unset($option); return $options;
    }
    private function name(string $name): void { if (trim($name) === '' || mb_strlen($name, 'UTF-8') > 255 || !mb_check_encoding($name, 'UTF-8')) { throw new \InvalidArgumentException('Invalid resource name.'); } }
    private function access(int $actor, bool $write = false): void
    {
        if (!(($this->authorize)($actor, null, 'core.manage')) || (!(($this->authorize)($actor, null, 'formstudio.resources.manage')) && ($write || !(($this->authorize)($actor, null, 'formstudio.forms.manage'))))) { throw new \DomainException('Option resources unavailable.'); }
    }
    private function audit(int $actor, string $event, int $id, int $revision): void
    {
        $this->db->insert('audit_log', ['correlation_id' => Uuid::create(), 'actor_id' => $actor, 'event_type' => $event, 'form_id' => null, 'submission_uuid' => null, 'created_at' => gmdate('Y-m-d H:i:s'), 'safe_metadata' => CanonicalJson::encode(['resource_id' => $id, 'revision' => $revision])]);
    }
}
