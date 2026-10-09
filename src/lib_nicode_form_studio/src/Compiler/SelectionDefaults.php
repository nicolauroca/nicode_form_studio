<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Compiler;

use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Registry\{FieldTypeRegistry, ProviderRegistry, RuleEffectRegistry};

/** Reject impossible literal initial choices without querying dynamic providers. */
final readonly class SelectionDefaults
{
    public function __construct(private FieldTypeRegistry $fields, private ProviderRegistry $sources, private RuleEffectRegistry $effects) {}

    public function validate(array $draft): array
    {
        $alternatives = []; $dynamic = []; $errors = [];
        foreach ($draft['rules'] as $rule) {
            if (!($rule['enabled'] ?? true)) { continue; }
            foreach ($rule['effects'] as $effect) {
                $target = $effect['target'];
                if (!$this->effects->get($effect['type']) instanceof \Nicode\FormStudio\Rules\CoreEffect) { $dynamic[$target] = true; }
                elseif ($effect['type'] === 'change_options') { $alternatives[$target] ??= []; array_push($alternatives[$target], ...$effect['value']); }
            }
        }
        foreach ($draft['fields'] as $i => $field) {
            $provider = $this->fields->get($field['type']);
            if ($provider->metadata()['datatype'] !== 'selection' || isset($dynamic[$field['uuid']])) { continue; }
            if (isset($field['source']) && !$this->sources->get($field['source']['type']) instanceof \Nicode\FormStudio\DataSource\StaticDataSource) { continue; }
            $options = isset($field['source']) ? $field['source']['config']['options'] : ($field['options'] ?? []);
            $options = [...$options, ...($alternatives[$field['uuid']] ?? [])];
            $allowed = array_column(array_filter($options, static fn (array $option): bool => (bool) ($option['enabled'] ?? true)), 'value');
            $initial = [];
            if (isset($field['config']['default'])) { $initial['/config/default'] = $field['config']['default']; }
            if (($field['prefill']['type'] ?? null) === 'constant') { $initial['/prefill/value'] = $field['prefill']['value']; }
            foreach ($initial as $path => $raw) {
                $value = $provider->normalize($raw, $field['config'] ?? []);
                foreach (is_array($value) ? $value : [$value] as $choice) {
                    if ($choice === null || $choice === '') { continue; }
                    if (!in_array($choice, $allowed, true)) {
                        $errors[] = new Diagnostic('field.initial.option', '/fields/' . $i . $path, 'Initial selection is not an enabled option in any declared option set.');
                        break;
                    }
                }
            }
        }
        return $errors;
    }
}
