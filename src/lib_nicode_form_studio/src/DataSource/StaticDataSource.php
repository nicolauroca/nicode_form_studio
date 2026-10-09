<?php
declare(strict_types=1);

namespace Nicode\FormStudio\DataSource;

use Nicode\FormStudio\Contract\DataSourceInterface;
use Nicode\FormStudio\Domain\Diagnostic;

/** Local or compiled OptionSet snapshot; identity and revision are preserved. */
final readonly class StaticDataSource implements DataSourceInterface
{
    public function __construct(private string $identifier = 'static')
    {
        if (!in_array($identifier, ['static', 'option_set'], true)) { throw new \InvalidArgumentException('Unknown static source mode.'); }
    }
    public function id(): string { return $this->identifier; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array
    {
        return ['id' => $this->id(), 'version' => $this->version(), 'cache' => true, 'context_keys' => [], 'ttl' => 0, 'max_ttl' => 86400, 'timeout' => 0,
            'failure_modes' => ['closed'], 'inputs' => 'declared field UUID map', 'output' => ['value' => 'string', 'label' => 'string'],
            'value_mapping' => 'value', 'label_mapping' => 'label', 'config_schema' => ['options' => 'ordered option list', 'revision' => 'pinned resource revision']];
    }
    public function validateConfiguration(array $configuration, string $path): array
    {
        $errors = [];
        if (isset($configuration['resource_hash']) && (!is_string($configuration['resource_hash']) || preg_match('/^[a-f0-9]{64}$/D', $configuration['resource_hash']) !== 1)) { $errors[] = new Diagnostic('source.resource.hash', $path, 'Invalid pinned resource hash.'); }
        if ($this->id() === 'option_set' && (!\Nicode\FormStudio\Domain\Uuid::valid($configuration['resource_uuid'] ?? null) || !is_int($configuration['revision'] ?? null) || $configuration['revision'] < 1)) {
            $errors[] = new Diagnostic('source.resource', $path, 'OptionSet requires stable resource identity and a pinned revision.');
        }
        if (!is_array($configuration['options'] ?? null) || !array_is_list($configuration['options'])) { return [...$errors, new Diagnostic('source.options', $path, 'Options snapshot is required.')]; }
        $seen = [];
        foreach ($configuration['options'] as $i => $option) {
            if (!is_array($option) || !is_string($option['value'] ?? null) || !is_string($option['label'] ?? null)) { $errors[] = new Diagnostic('source.option', "$path/options/$i", 'Separate string value and label required.'); continue; }
            if (isset($seen[$option['value']])) { $errors[] = new Diagnostic('source.option.duplicate', "$path/options/$i", 'Duplicate option value.'); }
            $seen[$option['value']] = true;
            foreach (['enabled', 'default'] as $flag) {
                if (array_key_exists($flag, $option) && !is_bool($option[$flag])) { $errors[] = new Diagnostic('source.option.flag', "$path/options/$i/$flag", 'Option flags must be booleans.'); }
            }
            if (isset($option['when']) && !is_array($option['when'])) { $errors[] = new Diagnostic('source.option.dependencies', "$path/options/$i", 'Expected a dependency-value map.'); }
            elseif (isset($option['when'])) {
                foreach ($option['when'] as $uuid => $value) {
                    if (!\Nicode\FormStudio\Domain\Uuid::valid($uuid) || (!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null)) { $errors[] = new Diagnostic('source.option.condition', "$path/options/$i/when", 'Conditions require field UUIDs and typed scalar values.'); }
                }
            }
        }
        return $errors;
    }
    public function options(array $configuration, array $inputs, array $trustedContext): array
    {
        $options = [];
        foreach ($configuration['options'] as $option) {
            if (!($option['enabled'] ?? true)) { continue; }
            foreach ($option['when'] ?? [] as $uuid => $expected) {
                if (!array_key_exists($uuid, $inputs) || $inputs[$uuid] !== $expected) { continue 2; }
            }
            $options[] = ['value' => $option['value'], 'label' => $option['label'], 'enabled' => true] + (array_key_exists('default', $option) ? ['default' => $option['default'] === true] : []);
        }
        return $options;
    }
}
