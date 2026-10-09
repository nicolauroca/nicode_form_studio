<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Compiler;

use Nicode\FormStudio\Domain\{Diagnostic, RepeatedInstances};

/** Static lexical-scope checks, including branches whose configured minimum is zero. */
final class RepeatedLayoutValidator
{
    public function validate(array $draft, int $budget = 10000): array
    {
        if (!in_array('repeatable-group', array_column($draft['elements'], 'type'), true)) { return []; }
        $errors = [];
        $error = static function (string $code, string $path, string $message) use (&$errors): void { $errors[] = new Diagnostic($code, $path, $message); };
        foreach ($draft['elements'] as $i => $element) {
            if (($element['type'] ?? null) !== 'repeatable-group') { continue; }
            $repeat = $element['repeat'] ?? null;
            if (!is_array($repeat) || count($repeat) !== 2 || !is_int($repeat['min'] ?? null) || !is_int($repeat['max'] ?? null) || $repeat['min'] < 0 || $repeat['max'] < $repeat['min'] || $repeat['max'] > $budget) {
                $error('layout.repeatable.limits', '/elements/' . $i . '/repeat', 'Specify integer min/max limits with 0 <= min <= max <= ' . $budget . '.');
            }
        }
        if ($errors !== []) { return $errors; }
        try { new RepeatedInstances($draft['elements'], [], $budget); }
        catch (\InvalidArgumentException) { return [new Diagnostic('layout.repeatable.structure', '/elements', 'Repeated layout requires valid identities, parents and bounded ancestry.')]; }
        $elements = array_column($draft['elements'], null, 'uuid'); $positions = array_flip(array_column($draft['elements'], 'uuid')); $scopes = []; $multiplicity = [];
        $expanded = 0; $rowCount = 0;
        foreach ($elements as $id => $element) {
            $scope = []; $parent = $element['parent_uuid'] ?? null;
            while ($parent !== null) {
                if ($elements[$parent]['type'] === 'repeatable-group') { array_unshift($scope, $parent); }
                $parent = $elements[$parent]['parent_uuid'] ?? null;
            }
            $scopes[$id] = $scope; $count = 1;
            foreach ($scope as $group) { $count = min($budget + 1, $count * $elements[$group]['repeat']['max']); }
            $multiplicity[$id] = $count; $expanded = min($budget + 1, $expanded + $count);
            if ($element['type'] === 'captcha' && $count > 1) { $error('layout.repeatable.captcha', '/elements/' . $positions[$id], 'CAPTCHA is form-wide and cannot expand into multiple row instances.'); }
            if ($element['type'] === 'repeatable-group') { $rowCount = min($budget + 1, $rowCount + $count * $element['repeat']['max']); }
        }
        if ($expanded > $budget || $rowCount > $budget) { $error('layout.repeatable.budget', '/elements', 'The configured maxima exceed the expanded layout/row budget of ' . $budget . '.'); }
        $prefix = static fn (array $reference, array $origin): bool => array_slice($origin, 0, count($reference)) === $reference;
        $reference = static function (mixed $origin, mixed $field, string $path) use ($scopes, $prefix, $error): void {
            if (!is_string($origin) || !is_string($field) || !isset($scopes[$origin], $scopes[$field])) { return; }
            if (!$prefix($scopes[$field], $scopes[$origin])) { $error('reference.repeatable.scope', $path, 'A row may reference fields in its own scope or an ancestor scope, not descendant or sibling rows.'); }
        };
        $comparable = static function (array $fields, string $path, int $nodes = 1) use ($scopes, $prefix, $multiplicity, $budget, $error): int {
            $deepest = []; $maximum = 1;
            foreach ($fields as $field) {
                if (!is_string($field) || !isset($scopes[$field])) { continue; }
                $candidate = $scopes[$field];
                if (!$prefix($candidate, $deepest) && !$prefix($deepest, $candidate)) { $error('reference.repeatable.scope', $path, 'References require one comparable lexical scope; cross-group aggregation is not implicit.'); return 0; }
                if (count($candidate) >= count($deepest)) { $deepest = $candidate; $maximum = $multiplicity[$field]; }
            }
            if ($maximum * $nodes > $budget) { $error('reference.repeatable.budget', $path, 'Expanded condition/validator evaluation exceeds the budget of ' . $budget . '.'); }
            return $maximum;
        };
        $conditionFields = static function (mixed $condition) use ($budget): array {
            $stack = [$condition]; $fields = []; $nodes = 0;
            while ($stack !== [] && $nodes <= $budget) {
                $node = array_pop($stack); ++$nodes;
                if (!is_array($node)) { continue; }
                if (is_array($node['children'] ?? null)) { array_push($stack, ...$node['children']); }
                elseif (is_string($node['field'] ?? null)) { $fields[] = $node['field']; }
            }
            return [$fields, $nodes];
        };
        $validatorCalls = 0; $validatorOverflow = false;
        $addValidator = static function (int $count, string $path) use (&$validatorCalls, &$validatorOverflow, $budget, $error): void {
            $validatorCalls = min($budget + 1, $validatorCalls + $count);
            if ($validatorCalls > $budget && !$validatorOverflow) {
                $validatorOverflow = true;
                $error('validator.repeatable.budget', $path, 'Combined field and form validators exceed the expanded call budget of ' . $budget . '.');
            }
        };
        foreach ($draft['fields'] as $i => $field) {
            $origin = $field['uuid'] ?? null; $path = '/fields/' . $i;
            if (($field['prefill']['type'] ?? null) === 'field') { $reference($origin, $field['prefill']['field'] ?? null, $path . '/prefill/field'); }
            foreach ($field['source']['dependencies'] ?? [] as $j => $dependency) { $reference($origin, $dependency, $path . '/source/dependencies/' . $j); }
            foreach ($field['validators'] ?? [] as $j => $validator) {
                $addValidator(is_string($origin) ? ($multiplicity[$origin] ?? 0) : 0, $path . '/validators/' . $j);
                $references = $validator['config']['fields'] ?? [];
                foreach (is_array($references) ? $references : [] as $k => $dependency) { $reference($origin, $dependency, $path . '/validators/' . $j . '/config/fields/' . $k); }
            }
        }
        foreach ($draft['validators'] ?? [] as $i => $validator) {
            $references = $validator['config']['fields'] ?? [];
            $contexts = $comparable(is_array($references) ? $references : [], '/validators/' . $i . '/config/fields');
            $addValidator($contexts, '/validators/' . $i);
        }
        $rules = 0; $conditionNodes = 0; $conditionOverflow = false;
        foreach ($draft['rules'] as $i => $rule) {
            [$fields, $nodes] = $conditionFields($rule['when'] ?? null);
            foreach ($rule['effects'] ?? [] as $j => $effect) {
                $target = $effect['target'] ?? null;
                foreach ($fields as $field) { $reference($target, $field, '/rules/' . $i . '/effects/' . $j . '/target'); }
                if (is_string($target)) {
                    $copies = $multiplicity[$target] ?? 0;
                    $rules = min($budget + 1, $rules + $copies);
                    $conditionNodes = min($budget + 1, $conditionNodes + $copies * $nodes);
                    if ($conditionNodes > $budget && !$conditionOverflow) {
                        $conditionOverflow = true;
                        $error('rule.repeatable.condition_budget', '/rules/' . $i . '/when', 'Combined expanded rule condition nodes exceed the budget of ' . $budget . '.');
                    }
                }
            }
        }
        if ($rules > $budget) { $error('rule.repeatable.budget', '/rules', 'Expanded rule effects exceed the budget of ' . $budget . '.'); }
        foreach ($draft['actions'] as $i => $action) {
            if (isset($action['condition'])) { [$fields, $nodes] = $conditionFields($action['condition']); $comparable($fields, '/actions/' . $i . '/condition', $nodes); }
            foreach (['email_field', 'reply_to_field'] as $key) {
                $field = $action['config'][$key] ?? null;
                if (is_string($field) && ($scopes[$field] ?? []) !== [] && !in_array($action['config'][$key . '_selection'] ?? null, \Nicode\FormStudio\Actions\EmailAction::ROW_SELECTIONS, true)) { $error('action.repeatable.recipient', '/actions/' . $i . '/config/' . $key, 'A repeated email field requires an explicit recipient selection policy.'); }
            }
        }
        foreach ($draft['post_submit']['conditional_messages'] ?? [] as $i => $candidate) {
            [$fields, $nodes] = $conditionFields($candidate['condition'] ?? null); $comparable($fields, '/post_submit/conditional_messages/' . $i . '/condition', $nodes);
        }
        return $errors;
    }
}
