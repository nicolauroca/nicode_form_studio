<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Registry;
use Nicode\FormStudio\Domain\FormSpec;

/** Derived deployment contract; never consults mutable authoring tables. */
final readonly class ProviderDependencies
{
    /** @param array<string, ProviderRegistry> $registries */
    public function __construct(private array $registries) {}
    public function manifest(array $definition): array
    {
        $used = [];
        $add = function (string $kind, string $id) use (&$used): void {
            $registry = $this->registries[$kind] ?? throw new \DomainException('Required provider registry unavailable.');
            $used[$kind][$id] = $registry->get($id)->version();
        };
        $condition = function (array $value) use (&$condition, $add): void {
            if (isset($value['group'])) { foreach ($value['children'] ?? [] as $child) { $condition($child); } }
            elseif (isset($value['operator'])) { $add('operators', $value['operator']); }
        };
        $validators = $definition['validators'] ?? [];
        foreach ($definition['fields'] as $field) {
            $add('fields', $field['type']);
            if (isset($field['source'])) { $add('sources', $field['source']['type']); }
            array_push($validators, ...($field['validators'] ?? []));
        }
        foreach ($validators as $validator) { $add('validators', $validator['type']); }
        foreach ($definition['rules'] as $rule) {
            $condition($rule['when']); foreach ($rule['effects'] as $effect) { $add('effects', $effect['type']); }
        }
        foreach ($definition['actions'] as $action) {
            $add('actions', $action['type']); if (isset($action['condition'])) { $condition($action['condition']); }
        }
        foreach ($definition['post_submit']['conditional_messages'] ?? [] as $candidate) { $condition($candidate['condition']); }
        ksort($used); foreach ($used as &$providers) { ksort($providers); } unset($providers);
        return $used;
    }
    public function assert(FormSpec $spec): void
    {
        $definition = $spec->toArray(); $current = $this->manifest($definition);
        // Pre-release legacy snapshots still require providers, but lack original version pins.
        if (!array_key_exists('provider_dependencies', $definition)) { return; }
        $required = $definition['provider_dependencies']; $this->compatible($required);
        foreach ($current as $kind => $providers) {
            foreach ($providers as $id => $version) { if (!isset($required[$kind][$id])) { throw new \DomainException('Published provider dependency is undeclared.'); } }
        }
    }
    public function compatible(mixed $required): void
    {
        if (!is_array($required)) { throw new \DomainException('Invalid provider dependency contract.'); }
        foreach ($required as $kind => $providers) {
            if (!isset($this->registries[$kind]) || !is_array($providers)) { throw new \DomainException('Unknown provider dependency kind.'); }
            foreach ($providers as $id => $version) {
                if (!is_string($id) || !is_string($version) || preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $version) !== 1) { throw new \DomainException('Invalid provider dependency version.'); }
                $installed = $this->registries[$kind]->get($id)->version();
                $installed = explode('+', $installed, 2)[0]; $version = explode('+', $version, 2)[0];
                $stable = explode('.', $version)[0] !== '0' && !str_contains($version, '-') && !str_contains($installed, '-');
                if (($stable && (explode('.', $installed)[0] !== explode('.', $version)[0] || version_compare($installed, $version, '<'))) || (!$stable && $installed !== $version)) { throw new \DomainException('Required provider version is incompatible.'); }
            }
        }
    }
}
