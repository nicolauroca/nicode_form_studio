<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Rendering;

use Nicode\FormStudio\Domain\FormSpec;
use Nicode\FormStudio\Registry\FieldTypeRegistry;

/** Explicit browser projection: never expose Actions, credentials or administrative config. */
final readonly class PublicSpec
{
    public function __construct(private FieldTypeRegistry $types, private ?BrowserProviders $browser = null) {}
    public function project(FormSpec $spec, ?\Nicode\FormStudio\Rules\RuleResult $state = null): array
    {
        return $this->projectDefinition($spec->toArray(), $state);
    }

    /** Public addressed graph; provider secrets still pass through the same allowlist. */
    public function projectInstances(FormSpec $spec, array $declarations, \Nicode\FormStudio\Rules\RuleResult $state, int $budget = 10000): array
    {
        $definition = $spec->toArray();
        $instances = new \Nicode\FormStudio\Domain\RepeatedInstances($definition['elements'], $declarations, $budget);
        [$expanded, $bindings] = \Nicode\FormStudio\Rules\RepeatedRuleExpansion::expand($definition, $instances, $budget);
        $mapValidator = static function (array $validator, \Nicode\FormStudio\Domain\FieldAddress $origin) use ($instances): array {
            if (isset($validator['config']['fields'])) {
                $validator['config']['fields'] = array_map(static fn (string $field): string => $instances->resolve($origin, $field)->key(), $validator['config']['fields']);
            }
            return $validator;
        };
        foreach ($expanded['fields'] as &$field) {
            $key = $field['uuid'];
            if (!isset($state->states[$key])) { throw new \InvalidArgumentException('Missing instance presentation state.'); }
            $origin = \Nicode\FormStudio\Domain\FieldAddress::fromKey($key);
            $field['validators'] = array_map(static fn (array $validator): array => $mapValidator($validator, $origin), $field['validators'] ?? []);
            if (isset($field['source'])) {
                $map = $bindings[$key] ?? [];
                $field['source']['dependencies'] = array_values($map);
                if (in_array($field['source']['type'], ['static', 'option_set'], true)) {
                    foreach ($field['source']['config']['options'] ?? [] as $i => $option) {
                        if (!isset($option['when'])) { continue; }
                        $when = [];
                        foreach ($option['when'] as $reference => $value) {
                            $when[$map[$reference] ?? throw new \InvalidArgumentException('Undeclared option dependency.')] = $value;
                        }
                        $field['source']['config']['options'][$i]['when'] = $when;
                    }
                }
            }
        }
        unset($field);
        $expanded['validators'] = [];
        foreach ($definition['validators'] ?? [] as $validator) {
            foreach ($instances->referenceContexts($validator['config']['fields'] ?? [], $budget) as $origin) {
                if (count($expanded['validators']) >= $budget) { throw new \InvalidArgumentException('Expanded public validator budget exceeded.'); }
                $expanded['validators'][] = $mapValidator($validator, $origin);
            }
        }
        $public = $this->projectDefinition($expanded, $state);
        foreach ($public['elements'] as $i => $element) {
            if ($element['type'] === 'repeatable-group') {
                $public['elements'][$i]['repeat'] = $expanded['elements'][$i]['repeat'];
                $public['elements'][$i]['title'] = $expanded['elements'][$i]['title'] ?? '';
            }
        }
        $public['instances'] = $instances->declarations();
        return $public;
    }

    private function projectDefinition(array $definition, ?\Nicode\FormStudio\Rules\RuleResult $state): array
    {
        $fields = []; $datatypes = []; $providers = [];
        $configKeys = ['label', 'required', 'readonly', 'disabled', 'visible', 'default', 'trim', 'min_length', 'max_length', 'min', 'max', 'step', 'scale', 'precision', 'pattern', 'min_selections', 'max_selections', 'extensions', 'mime_types', 'max_bytes', 'max_files'];
        foreach ($definition['fields'] as $field) {
            $config = array_intersect_key($field['config'] ?? [], array_flip($configKeys));
            $descriptor = $this->provider('fields', $field['type'], $providers);
            $config += BrowserProviders::configuration($field['config'] ?? [], $descriptor);
            if (isset($field['config']['validation_messages'])) { $config['validation_messages'] = array_filter($field['config']['validation_messages'], 'is_string'); }
            if ($field['type'] === 'password') { unset($config['default']); }
            $public = ['uuid' => $field['uuid'], 'type' => $field['type'], 'config' => $config, 'options' => $this->options($field['options'] ?? [])];
            $public['index_type'] = ($field['index'] ?? false) && ($field['persist'] ?? true) ? $this->types->get($field['type'])->indexType() : null;
            $public['validators'] = $this->validators($field['validators'] ?? [], $providers);
            if (($field['prefill']['type'] ?? null) === 'field' && \Nicode\FormStudio\Field\Prefill::authoritative($field) && ($state === null || isset($state->initialDefaults[$field['uuid']]))) { $public['prefill'] = $field['prefill']; }
            if (\Nicode\FormStudio\Field\Prefill::authoritative($field) && $this->types->get($field['type'])->metadata()['datatype'] === 'selection'
                && ($state === null || isset($state->initialDefaults[$field['uuid']]))
                && (($field['prefill']['type'] ?? null) === 'source' || (!isset($field['prefill']) && !array_key_exists('default', $field['config'] ?? [])))) {
                $public['option_defaults'] = $this->types->get($field['type'])->multiple() ? 'multiple' : 'single';
            }
            if (isset($field['source'])) {
                // Only local snapshots are safe to expose directly. Other sources use an ACL endpoint.
                $source = $field['source'];
                if (in_array($source['type'], ['static', 'option_set'], true)) { $public['source'] = ['type' => $source['type'], 'dependencies' => $source['dependencies'] ?? [], 'config' => ['options' => $this->options($source['config']['options'] ?? [], true)]]; }
                else {
                    $public['source'] = ['type' => 'remote', 'dependencies' => $source['dependencies'] ?? []];
                    $public['options'] = $this->options(($state?->states[$field['uuid']]['active'] ?? false) ? $state->states[$field['uuid']]['options'] : []);
                }
            }
            $fields[] = $public;
            $datatypes[$field['uuid']] = $this->types->get($field['type'])->metadata()['datatype'];
        }
        $rules = [];
        foreach ($definition['rules'] as $rule) { $rules[] = $this->rule($rule, $providers); }
        $validators = $this->validators($definition['validators'] ?? [], $providers);
        return ['schema_version' => $definition['schema_version'], 'uuid' => $definition['uuid'],
            'elements' => array_map(static fn (array $element): array => array_intersect_key($element, array_flip(['uuid', 'type', 'parent_uuid', 'visible'])), $definition['elements']),
            'fields' => $fields, 'rules' => $rules, 'validators' => $validators, 'datatypes' => $datatypes, 'browser_providers' => $providers];
    }

    private function provider(string $kind, string $id, array &$providers): ?array
    {
        $descriptor = $this->browser?->descriptor($kind, $id);
        if ($descriptor !== null) { $providers[$kind][$id] = array_intersect_key($descriptor, array_flip(['version', 'module', 'styles'])); }
        return $descriptor;
    }

    private function validators(array $validators, array &$providers): array
    {
        return array_map(function (array $validator) use (&$providers): array {
            $descriptor = $this->provider('validators', $validator['type'], $providers);
            if ($descriptor !== null) { return ['type' => $validator['type'], 'config' => BrowserProviders::configuration($validator['config'] ?? [], $descriptor)]; }
            if (!in_array($validator['type'], \Nicode\FormStudio\Validation\RelationalValidator::IDS, true)) {
                // Third-party validation remains authoritative on the server;
                // its arbitrary provider configuration is never public by default.
                return ['type' => $validator['type'], 'server_only' => true];
            }
            return ['type' => $validator['type'], 'config' => array_intersect_key($validator['config'] ?? [], array_flip(['fields', 'count']))];
        }, $validators);
    }

    private function options(array $options, bool $dependent = false): array
    {
        $keys = array_flip($dependent ? ['uuid', 'value', 'label', 'enabled', 'default', 'when'] : ['uuid', 'value', 'label', 'enabled', 'default']);
        return array_map(static fn (array $option): array => array_intersect_key($option, $keys), $options);
    }

    private function rule(array $rule, array &$providers): array
    {
        $public = array_intersect_key($rule, array_flip(['uuid', 'priority', 'enabled']));
        $public['when'] = $this->condition($rule['when'], $providers);
        $public['effects'] = array_map(function (array $effect) use (&$providers): array {
            $result = array_intersect_key($effect, array_flip(['target', 'type', 'value']));
            $result += BrowserProviders::configuration($effect, $this->provider('effects', $effect['type'], $providers));
            if ($effect['type'] === 'change_options') { $result['value'] = $this->options($effect['value']); }
            return $result;
        }, $rule['effects']);
        return $public;
    }

    private function condition(array $condition, array &$providers): array
    {
        if (isset($condition['group'])) {
            $children = [];
            foreach ($condition['children'] as $child) { $children[] = $this->condition($child, $providers); }
            return ['group' => $condition['group'], 'children' => $children];
        }
        $this->provider('operators', $condition['operator'], $providers);
        return array_intersect_key($condition, array_flip(['field', 'operator', 'value']));
    }
}
