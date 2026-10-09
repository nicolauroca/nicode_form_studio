<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Rules;

use Nicode\FormStudio\Domain\CanonicalJson;
use Nicode\FormStudio\Domain\FormSpec;
use Nicode\FormStudio\Registry\FieldTypeRegistry;
use Nicode\FormStudio\Registry\RuleEffectRegistry;
use Nicode\FormStudio\DataSource\OptionResolver;

final readonly class RuleEngine
{
    public function __construct(private ConditionEvaluator $conditions, private RuleEffectRegistry $effects, private FieldTypeRegistry $types, private int $maxIterations = 64, private ?OptionResolver $options = null)
    {
        if ($maxIterations < 1) { throw new \InvalidArgumentException('Iteration limit must be positive.'); }
    }

    public function evaluate(FormSpec $spec, array $normalized, array $trustedContext = [], array $initialDefaults = []): RuleResult
    {
        return $this->evaluateData($spec->toArray(), $normalized, $trustedContext, $initialDefaults);
    }

    /** Inputs have already passed normalization and trusted-prefill policy. */
    public function evaluateInstances(FormSpec $spec, array $declarations, array $normalized, array $trustedContext = [], array $initialDefaults = [], int $budget = 10000): RuleResult
    {
        $data = $spec->toArray();
        $instances = new \Nicode\FormStudio\Domain\RepeatedInstances($data['elements'], $declarations, $budget);
        $normalized = $instances->bind($normalized, $budget);
        foreach ($initialDefaults as $key => $_) {
            if (!array_key_exists($key, $normalized)) { throw new \InvalidArgumentException('Unknown initial-default address.'); }
        }
        [$expanded, $bindings] = RepeatedRuleExpansion::expand($data, $instances, $budget);
        return $this->evaluateData($expanded, $normalized, $trustedContext, $initialDefaults, $bindings);
    }

    private function evaluateData(array $data, array $normalized, array $trustedContext, array $initialDefaults, array $sourceBindings = []): RuleResult
    {
        $baseline = [];
        $parents = [];
        $datatypes = [];
        foreach ($data['elements'] as $element) {
            $uuid = $element['uuid'];
            $parents[$uuid] = $element['parent_uuid'] ?? null;
            $baseline[$uuid] = ['visible' => $element['visible'] ?? true, 'enabled' => true, 'required' => false, 'value' => null, 'options' => [], 'active' => true];
        }
        foreach ($data['fields'] as $field) {
            $uuid = $field['uuid']; $config = $field['config'] ?? [];
            $datatypes[$uuid] = $this->types->get($field['type'])->metadata()['datatype'];
            $baseline[$uuid] = array_replace($baseline[$uuid], [
                'visible' => $config['visible'] ?? $baseline[$uuid]['visible'], 'enabled' => !($config['disabled'] ?? false),
                'required' => $config['required'] ?? false, 'value' => $normalized[$uuid] ?? null,
                'options' => $field['options'] ?? [],
            ]);
        }
        $rules = $data['rules'];
        usort($rules, static fn (array $a, array $b): int => (($a['priority'] ?? 0) <=> ($b['priority'] ?? 0)) ?: strcmp($a['uuid'], $b['uuid']));
        $current = $this->inherit($baseline, $parents);
        $seen = [];
        for ($iteration = 1; $iteration <= $this->maxIterations; $iteration++) {
            $fingerprint = CanonicalJson::encode($current);
            if (isset($seen[$fingerprint])) { throw new \DomainException('Rule configuration does not converge.'); }
            $seen[$fingerprint] = true;
            $values = array_map(static fn (array $state): mixed => $state['active'] ? $state['value'] : null, $current);
            // Rebuild declarative state on every pass so effects retract when conditions stop matching.
            $next = $baseline;
            $optionDefaults = [];
            $normalizationErrors = [];
            foreach ($data['fields'] as $field) {
                if (isset($field['source'])) {
                    if ($this->options === null) { throw new \DomainException('Data source resolver is required.'); }
                    $sourceValues = $values;
                    if (isset($sourceBindings[$field['uuid']])) {
                        $sourceValues = [];
                        foreach ($sourceBindings[$field['uuid']] as $definitionId => $address) { $sourceValues[$definitionId] = $values[$address] ?? null; }
                    }
                    $next[$field['uuid']]['options'] = $this->options->resolve($field['source'], $sourceValues, $trustedContext);
                }
                $uuid = $field['uuid'];
                if (isset($initialDefaults[$uuid]) && ($field['prefill']['type'] ?? null) === 'field') {
                    try { $next[$uuid]['value'] = $this->types->get($field['type'])->normalize($values[$field['prefill']['field']] ?? null, $field['config'] ?? []); }
                    catch (\InvalidArgumentException) { $next[$uuid]['value'] = null; $normalizationErrors[$uuid] = ['type']; }
                }
                if (isset($initialDefaults[$uuid]) && $datatypes[$uuid] === 'selection' && (($field['prefill']['type'] ?? null) === 'source' || (!isset($field['prefill']) && !array_key_exists('default', $field['config'] ?? [])))) {
                    $optionDefaults[$uuid] = $this->types->get($field['type'])->multiple();
                    $next[$uuid]['value'] = $this->optionDefault($next[$uuid]['options'], $optionDefaults[$uuid]);
                }
            }
            foreach ($rules as $rule) {
                if (($rule['enabled'] ?? true) && $this->conditions->matches($rule['when'], $values, $datatypes)) {
                    foreach ($rule['effects'] as $effect) {
                        $target = $effect['target'];
                        $previousValue = $next[$target]['value'];
                        $next[$target] = $this->effects->get($effect['type'])->apply($next[$target], $effect);
                        // Explicit value effects retain precedence, including a
                        // clear/set whose value already equals the initial value.
                        if ($next[$target]['value'] !== $previousValue || in_array($effect['type'], ['set_value', 'clear_value'], true)) { unset($optionDefaults[$target], $normalizationErrors[$target]); }
                    }
                }
            }
            foreach ($optionDefaults as $uuid => $multiple) { $next[$uuid]['value'] = $this->optionDefault($next[$uuid]['options'], $multiple); }
            $next = $this->inherit($next, $parents);
            if (CanonicalJson::encode($next) === $fingerprint) {
                $accepted = [];
                foreach ($data['fields'] as $field) {
                    $uuid = $field['uuid'];
                    if ($next[$uuid]['active']) { $accepted[$uuid] = $next[$uuid]['value']; }
                }
                return new RuleResult($next, $accepted, $iteration, $initialDefaults, $normalizationErrors);
            }
            $current = $next;
        }
        throw new \DomainException('Rule iteration limit exceeded.');
    }

    private function optionDefault(array $options, bool $multiple): mixed
    {
        $defaults = array_column(array_filter($options, static fn (array $option): bool => ($option['enabled'] ?? true) && ($option['default'] ?? false) === true), 'value');
        return $multiple ? $defaults : ($defaults[0] ?? null);
    }

    private function inherit(array $states, array $parents): array
    {
        foreach ($states as $uuid => &$state) {
            $state['active'] = $state['visible'] && $state['enabled'];
            $parent = $parents[$uuid];
            $seen = [$uuid => true];
            while ($parent !== null) {
                if (isset($seen[$parent]) || !isset($states[$parent])) { throw new \DomainException('Invalid layout ancestry.'); }
                $seen[$parent] = true;
                $state['active'] = $state['active'] && $states[$parent]['visible'] && $states[$parent]['enabled'];
                $parent = $parents[$parent];
            }
        }
        unset($state);
        return $states;
    }
}
