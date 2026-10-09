<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Rules;

use Nicode\FormStudio\Domain\{FieldAddress, RepeatedInstances};

/** Transient execution graph; never a published snapshot or provider definition. */
final class RepeatedRuleExpansion
{
    public static function expand(array $data, RepeatedInstances $instances, int $budget): array
    {
        $elements = array_column($data['elements'], null, 'uuid');
        $fields = array_column($data['fields'], null, 'uuid');
        $expanded = array_replace($data, ['elements' => [], 'fields' => [], 'rules' => []]);
        $addresses = []; $bindings = [];
        foreach ($instances->elementAddresses($budget) as $address) {
            $key = $address->key(); $element = $elements[$address->field];
            $addresses[$address->field][] = $address;
            $element['uuid'] = $key;
            $parent = $element['parent_uuid'] ?? null;
            $scope = $address->instances;
            if ($parent !== null && ($elements[$parent]['type'] ?? null) === 'repeatable-group') { array_pop($scope); }
            $element['parent_uuid'] = $parent === null ? null : (new FieldAddress($parent, $scope))->key();
            $expanded['elements'][] = $element;
            if ($element['type'] !== 'field') { continue; }
            if (!isset($fields[$address->field])) { throw new \InvalidArgumentException('Missing field definition.'); }
            $field = $fields[$address->field]; $field['uuid'] = $key;
            if (($field['prefill']['type'] ?? null) === 'field') {
                $field['prefill']['field'] = $instances->resolve($address, $field['prefill']['field'])->key();
            }
            foreach ($field['source']['dependencies'] ?? [] as $dependency) {
                $bindings[$key][$dependency] = $instances->resolve($address, $dependency)->key();
            }
            $expanded['fields'][] = $field;
        }
        $count = 0; $conditionNodes = 0;
        foreach ($data['rules'] as $rule) {
            foreach ($rule['effects'] as $ordinal => $effect) {
                if (!isset($elements[$effect['target']])) { throw new \InvalidArgumentException('Unknown rule target.'); }
                foreach ($addresses[$effect['target']] ?? [] as $address) {
                    if (++$count > $budget) { throw new \InvalidArgumentException('Expanded rule budget exceeded.'); }
                    $copy = $rule;
                    $copy['uuid'] .= '/' . sprintf('%010d', $ordinal) . '/' . $address->key();
                    $copy['when'] = self::condition($rule['when'], $address, $instances, $conditionNodes, $budget);
                    $effectCopy = $effect; $effectCopy['target'] = $address->key();
                    $copy['effects'] = [$effectCopy];
                    $expanded['rules'][] = $copy;
                }
            }
        }
        return [$expanded, $bindings];
    }

    private static function condition(array $condition, FieldAddress $origin, RepeatedInstances $instances, int &$nodes, int $budget, int $depth = 0): array
    {
        if (++$nodes > $budget) { throw new \InvalidArgumentException('Expanded rule condition budget exceeded.'); }
        if ($depth > 64) { throw new \InvalidArgumentException('Condition nesting exceeds safe depth.'); }
        if (isset($condition['group'])) {
            foreach ($condition['children'] as &$child) { $child = self::condition($child, $origin, $instances, $nodes, $budget, $depth + 1); }
            unset($child);
        } else { $condition['field'] = $instances->resolve($origin, $condition['field'])->key(); }
        return $condition;
    }
}
