<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Domain\{CanonicalJson, ConcurrentEdit, DefinitionRemapper, Uuid};
use Nicode\FormStudio\Infrastructure\Database\Connection;
use Nicode\FormStudio\Registry\{DataSourceRegistry, FieldTypeRegistry, ProviderDependencies};

/** Reusable portable configurations copied into drafts, never mutable runtime lookups. */
final readonly class DataSources
{
    public function __construct(private Connection $db, private FormAdministration $forms, private FormExchange $exchange, private DataSourceRegistry $providers, private FieldTypeRegistry $fields, private DefinitionRemapper $remapper, private \Closure $authorize) {}
    public function listing(int $actor, int $before = PHP_INT_MAX): array
    {
        $this->access($actor); if ($before < 1) { throw new \InvalidArgumentException('Invalid source cursor.'); }
        $rows = $this->db->rows('SELECT id, uuid, name, provider, provider_version, enabled, revision FROM ' . $this->db->table('data_sources') . ' WHERE id < :before ORDER BY id DESC LIMIT 101', [':before' => $before]);
        $more = count($rows) > 100; if ($more) { array_pop($rows); }
        return ['rows' => $rows, 'next_before' => $more ? (int) end($rows)['id'] : null, 'can_edit' => (bool) ($this->authorize)($actor, null, 'formstudio.resources.manage')];
    }
    public function read(int $actor, int $id): array
    {
        $this->access($actor);
        $row = $this->db->row('SELECT * FROM ' . $this->db->table('data_sources') . ' WHERE id = :id', [':id' => $id]) ?? throw new \OutOfBoundsException('Source resource unavailable.');
        $row['id'] = (int) $row['id']; $row['revision'] = (int) $row['revision']; $row['enabled'] = (bool) $row['enabled'];
        $row['definition'] = json_decode($row['config'], true, 128, JSON_THROW_ON_ERROR); unset($row['config']); return $row;
    }
    public function capture(int $actor, string $name, int $form, int $formRevision, string $fieldUuid, int $id = 0, int $expected = 0): array
    {
        $this->access($actor, true); $this->name($name);
        return $this->db->transaction(function () use ($actor, $name, $form, $formRevision, $fieldUuid, $id, $expected): array {
            $this->db->row('SELECT id FROM ' . $this->db->table('forms') . ' WHERE id = :id FOR UPDATE', [':id' => $form]);
            $edit = $this->forms->edit($form, $actor);
            if ((int) $edit['form']['draft_revision'] !== $formRevision) { throw new ConcurrentEdit('Source form changed.'); }
            $package = $this->exchange->export($form, 0, $actor, 'portable');
            $fields = array_column($package['definition']['fields'], null, 'uuid'); $field = $fields[$fieldUuid] ?? throw new \OutOfBoundsException('Source field unavailable.');
            $source = $field['source'] ?? throw new \InvalidArgumentException('Configure and save a source first.'); unset($source['resource']);
            $this->validate($source); $parameters = [];
            foreach ($source['dependencies'] ?? [] as $uuid) {
                $dependency = $fields[$uuid] ?? throw new \InvalidArgumentException('Source dependency unavailable.'); $alias = $dependency['name'];
                if (!is_string($alias) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $alias) !== 1 || isset($parameters[$alias])) { throw new \InvalidArgumentException('Source dependency names must be distinct.'); }
                $provider = $this->fields->get($dependency['type']);
                $parameters[$alias] = ['uuid' => $uuid, 'datatype' => $provider->metadata()['datatype'], 'multiple' => $provider->multiple()];
            }
            $definition = CanonicalJson::encode(['source' => $source, 'parameters' => $parameters]);
            $version = $this->providers->get($source['type'])->version();
            if ($id === 0) {
                if ($expected !== 0) { throw new \InvalidArgumentException('Unexpected new resource revision.'); }
                $next = 1; $id = $this->db->insert('data_sources', ['uuid' => Uuid::create(), 'name' => $name, 'provider' => $source['type'], 'provider_version' => $version, 'config' => $definition, 'enabled' => 1, 'revision' => $next]);
            } else {
                $this->lock($id, $expected); $next = $expected + 1;
                $this->db->execute('UPDATE ' . $this->db->table('data_sources') . ' SET name = :name, provider = :provider, provider_version = :version, config = :config, revision = :revision WHERE id = :id', [':name' => $name, ':provider' => $source['type'], ':version' => $version, ':config' => $definition, ':revision' => $next, ':id' => $id]);
            }
            $this->audit($actor, $id, $next, 'capture'); return ['id' => $id, 'revision' => $next];
        });
    }
    public function configure(int $actor, int $id, int $expected, string $name, bool $enabled): int
    {
        $this->access($actor, true); $this->name($name);
        return $this->db->transaction(function () use ($actor, $id, $expected, $name, $enabled): int {
            $this->lock($id, $expected); $next = $expected + 1;
            $this->db->execute('UPDATE ' . $this->db->table('data_sources') . ' SET name = :name, enabled = :enabled, revision = :revision WHERE id = :id', [':name' => $name, ':enabled' => (int) $enabled, ':revision' => $next, ':id' => $id]);
            $this->audit($actor, $id, $next, 'configure'); return $next;
        });
    }
    public function bind(int $actor, int $id, int $expected, int $form, string $target, array $bindings): array
    {
        $resource = $this->read($actor, $id);
        if ($expected !== $resource['revision']) { throw new ConcurrentEdit('Source revision changed.'); }
        if (!$resource['enabled']) { throw new \DomainException('Source resource is disabled.'); }
        (new ProviderDependencies(['sources' => $this->providers]))->compatible(['sources' => [$resource['provider'] => $resource['provider_version']]]);
        $draft = $this->forms->edit($form, $actor)['draft']; $fields = array_column($draft['fields'], null, 'uuid');
        if (!isset($fields[$target]) || $this->fields->get($fields[$target]['type'])->metadata()['datatype'] !== 'selection') { throw new \InvalidArgumentException('Select a destination selection field.'); }
        $parameters = $resource['definition']['parameters'];
        if (array_diff(array_keys($bindings), array_keys($parameters)) !== [] || array_diff(array_keys($parameters), array_keys($bindings)) !== []) { throw new \InvalidArgumentException('Explicit bindings are required for every source parameter.'); }
        $map = []; $used = [];
        foreach ($parameters as $name => $parameter) {
            $uuid = $bindings[$name];
            if (!is_string($uuid) || !isset($fields[$uuid]) || $uuid === $target || isset($used[$uuid])) { throw new \InvalidArgumentException('Invalid or ambiguous source binding.'); }
            $provider = $this->fields->get($fields[$uuid]['type']);
            if ($provider->metadata()['datatype'] !== $parameter['datatype'] || $provider->multiple() !== $parameter['multiple']) { throw new \InvalidArgumentException('Incompatible source binding type.'); }
            $map[$parameter['uuid']] = $uuid; $used[$uuid] = true;
        }
        $source = $this->remapper->source($resource['definition']['source'], $map); $this->validate($source);
        $source['resource'] = ['uuid' => $resource['uuid'], 'revision' => $expected, 'hash' => hash('sha256', CanonicalJson::encode($resource['definition']))];
        return ['source' => $source];
    }
    private function validate(array $source): void
    {
        if (!is_string($source['type'] ?? null) || !$this->providers->has($source['type']) || !is_array($source['config'] ?? null) || !is_array($source['dependencies'] ?? []) || !array_is_list($source['dependencies'] ?? [])) { throw new \InvalidArgumentException('Invalid portable source.'); }
        $provider = $this->providers->get($source['type']);
        if ($provider->validateConfiguration($source['config'], '/source/config') !== []) { throw new \InvalidArgumentException('Portable source requires configuration before reuse.'); }
        $dependencies = $source['dependencies'] ?? [];
        foreach ($dependencies as $uuid) { if (!Uuid::valid($uuid)) { throw new \InvalidArgumentException('Invalid source dependency.'); } }
        if (count(array_unique($dependencies)) !== count($dependencies)) { throw new \InvalidArgumentException('Duplicate source dependency.'); }
        foreach ($provider->metadata()['dependency_parameters'] ?? [] as $parameter) {
            $reference = $source['config'][$parameter] ?? null;
            if ($reference !== null && !in_array($reference, $dependencies, true)) { throw new \InvalidArgumentException('Provider parameter refers to an undeclared source dependency.'); }
        }
        if (in_array($source['type'], ['static', 'option_set'], true)) {
            foreach ($source['config']['options'] ?? [] as $option) {
                foreach (array_keys($option['when'] ?? []) as $reference) {
                    if (!in_array($reference, $dependencies, true)) { throw new \InvalidArgumentException('Option condition refers to an undeclared source dependency.'); }
                }
            }
        }
        $ttl = $source['ttl'] ?? 0; if (!is_int($ttl) || $ttl < 0 || $ttl > 86400) { throw new \InvalidArgumentException('Invalid source cache lifetime.'); }
    }
    private function lock(int $id, int $expected): void
    {
        $row = $this->db->row('SELECT revision FROM ' . $this->db->table('data_sources') . ' WHERE id = :id FOR UPDATE', [':id' => $id]) ?? throw new \OutOfBoundsException('Source resource unavailable.');
        if ($expected < 1 || (int) $row['revision'] !== $expected) { throw new ConcurrentEdit('Source resource changed.'); }
    }
    private function name(string $name): void { if (trim($name) === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 255) { throw new \InvalidArgumentException('Invalid source resource name.'); } }
    private function access(int $actor, bool $write = false): void
    {
        if (!(($this->authorize)($actor, null, 'core.manage')) || (!(($this->authorize)($actor, null, 'formstudio.resources.manage')) && ($write || !(($this->authorize)($actor, null, 'formstudio.forms.manage'))))) { throw new \DomainException('Source resources unavailable.'); }
    }
    private function audit(int $actor, int $id, int $revision, string $operation): void
    {
        $this->db->insert('audit_log', ['correlation_id' => Uuid::create(), 'actor_id' => $actor, 'event_type' => 'resource.data_source.' . $operation, 'form_id' => null, 'submission_uuid' => null, 'created_at' => gmdate('Y-m-d H:i:s'), 'safe_metadata' => CanonicalJson::encode(['resource_id' => $id, 'revision' => $revision])]);
    }
}
