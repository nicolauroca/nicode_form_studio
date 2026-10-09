<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Domain;

/** Validated instance membership; minima are reported for later active-scope validation. */
final class RepeatedInstances
{
    private array $elements = [];
    private array $ancestors = [];
    private array $rows = [];
    private array $minimumErrors = [];

    public function __construct(array $elements, array $declarations, int $budget = 10000)
    {
        if ($budget < 1 || count($elements) > $budget || count($declarations) > $budget) { throw new \InvalidArgumentException('Repeated instance budget exceeded.'); }
        foreach ($elements as $element) {
            $id = $element['uuid'] ?? null;
            if (!Uuid::valid($id) || isset($this->elements[$id])) { throw new \InvalidArgumentException('Invalid element identity.'); }
            $this->elements[$id] = $element;
        }
        $children = [];
        foreach ($this->elements as $id => $element) {
            $parents = []; $seen = [$id => true]; $parent = $element['parent_uuid'] ?? null; $depth = 0;
            while ($parent !== null) {
                if (!Uuid::valid($parent) || isset($seen[$parent]) || !isset($this->elements[$parent]) || ++$depth > 64) { throw new \InvalidArgumentException('Invalid repeated ancestry.'); }
                if (!in_array($this->elements[$parent]['type'] ?? null, ['section', 'group', 'fieldset', 'row', 'columns', 'panel', 'step', 'repeatable-group'], true)) { throw new \InvalidArgumentException('Parent is not a layout container.'); }
                $seen[$parent] = true;
                if (($this->elements[$parent]['type'] ?? null) === 'repeatable-group') { array_unshift($parents, $parent); }
                $parent = $this->elements[$parent]['parent_uuid'] ?? null;
            }
            $this->ancestors[$id] = $parents;
            if (($element['type'] ?? null) === 'repeatable-group') {
                $repeat = $element['repeat'] ?? [];
                if (!is_array($repeat) || count($repeat) !== 2 || !is_int($repeat['min'] ?? null) || !is_int($repeat['max'] ?? null) || $repeat['min'] < 0 || $repeat['max'] < $repeat['min'] || $repeat['max'] > $budget) { throw new \InvalidArgumentException('Invalid repetition limits.'); }
                $children[$parents === [] ? '' : $parents[array_key_last($parents)]][] = $id;
            }
        }
        $total = 0;
        foreach ($declarations as $key => $rows) {
            if (!is_string($key) || !is_array($rows) || !array_is_list($rows) || ($total += count($rows)) > $budget) { throw new \InvalidArgumentException('Invalid instance declaration.'); }
            $address = FieldAddress::fromKey($key);
            if (($this->elements[$address->field]['type'] ?? null) !== 'repeatable-group' || array_column($address->instances, 'group') !== $this->ancestors[$address->field]) { throw new \InvalidArgumentException('Instance scope does not belong to the layout.'); }
            if (count($rows) > $this->elements[$address->field]['repeat']['max']) { throw new \InvalidArgumentException('Maximum repetitions exceeded.'); }
            $unique = [];
            foreach ($rows as $row) { if (!Uuid::valid($row) || isset($unique[$row])) { throw new \InvalidArgumentException('Invalid or duplicate instance.'); } $unique[$row] = true; }
        }
        $visited = []; $scopes = 0;
        $walk = function (string $group, array $parents) use (&$walk, &$visited, &$scopes, $budget, $children, $declarations): void {
            if (++$scopes > $budget) { throw new \InvalidArgumentException('Repeated scope budget exceeded.'); }
            $key = (new FieldAddress($group, $parents))->key(); $rows = $declarations[$key] ?? [];
            $visited[$key] = true; $this->rows[$key] = $rows;
            if (count($rows) < $this->elements[$group]['repeat']['min']) { $this->minimumErrors[$key] = ['min_instances']; }
            foreach ($rows as $row) {
                foreach ($children[$group] ?? [] as $child) { $walk($child, [...$parents, ['group' => $group, 'instance' => $row]]); }
            }
        };
        foreach ($children[''] ?? [] as $group) { $walk($group, []); }
        if (array_diff_key($declarations, $visited) !== []) { throw new \InvalidArgumentException('Instance parent is not declared.'); }
    }

    public function contains(FieldAddress $address): bool
    {
        return ($this->elements[$address->field]['type'] ?? null) === 'field' && $this->containsElement($address);
    }

    public function containsElement(FieldAddress $address): bool
    {
        if (!isset($this->elements[$address->field]) || array_column($address->instances, 'group') !== $this->ancestors[$address->field]) { return false; }
        $parents = [];
        foreach ($address->instances as $instance) {
            $key = (new FieldAddress($instance['group'], $parents))->key();
            if (!in_array($instance['instance'], $this->rows[$key] ?? [], true)) { return false; }
            $parents[] = $instance;
        }
        return true;
    }

    public function declarations(): array { return $this->rows; }
    public function minimumErrors(): array { return $this->minimumErrors; }

    /** Return a new declaration set; existing rows and their ordering are untouched. */
    public function withAddedRow(FieldAddress $group, int $budget = 10000): self
    {
        $key = $this->groupKey($group);
        if (count($this->rows[$key]) >= $this->elements[$group->field]['repeat']['max']) { throw new \InvalidArgumentException('Maximum repetitions reached.'); }
        // Seed just this branch, never regenerate the rest of the form.
        $branch = [];
        foreach ($this->elements as $id => $element) {
            $cursor = $id;
            while ($cursor !== null && $cursor !== $group->field) { $cursor = $this->elements[$cursor]['parent_uuid'] ?? null; }
            if ($cursor === null) { continue; }
            if ($id === $group->field) { $element['parent_uuid'] = null; $element['repeat'] = ['min' => 1, 'max' => 1]; }
            $branch[] = $element;
        }
        $seed = self::initial($branch, $budget)->declarations();
        $declarations = $this->rows;
        $declarations[$key][] = $seed[$group->field][0];
        foreach ($seed as $local => $rows) {
            if ($local === $group->field) { continue; }
            $address = FieldAddress::fromKey($local);
            $declarations[(new FieldAddress($address->field, [...$group->instances, ...$address->instances]))->key()] = $rows;
        }
        $result = new self(array_values($this->elements), $declarations, $budget);
        $result->elementAddresses($budget);
        return $result;
    }

    /** Remove exactly one row and its descendant declarations, retaining all sibling IDs. */
    public function withRemovedRow(FieldAddress $group, string $row, int $budget = 10000): self
    {
        $key = $this->groupKey($group);
        if (!in_array($row, $this->rows[$key], true)) { throw new \InvalidArgumentException('Unknown row.'); }
        if (count($this->rows[$key]) <= $this->elements[$group->field]['repeat']['min']) { throw new \InvalidArgumentException('Minimum repetitions reached.'); }
        $declarations = $this->rows;
        $declarations[$key] = array_values(array_filter($declarations[$key], static fn (string $id): bool => $id !== $row));
        $prefix = $key . '/' . $row . '/';
        foreach (array_keys($declarations) as $address) { if (str_starts_with($address, $prefix)) { unset($declarations[$address]); } }
        $result = new self(array_values($this->elements), $declarations, $budget);
        $result->elementAddresses($budget);
        return $result;
    }

    private function groupKey(FieldAddress $group): string
    {
        if (($this->elements[$group->field]['type'] ?? null) !== 'repeatable-group' || !$this->containsElement($group)) { throw new \InvalidArgumentException('Unknown group scope.'); }
        return $group->key();
    }

    /** Create only the minimum rows for a new presentation; never repair submitted rows. */
    public static function initial(array $elements, int $budget = 10000): self
    {
        $layout = new self($elements, [], $budget);
        $children = [];
        foreach ($layout->elements as $id => $element) { $children[$element['parent_uuid'] ?? ''][] = $id; }
        $declarations = []; $visited = 0; $rows = 0;
        $walk = function (string $id, array $scope) use (&$walk, &$declarations, &$visited, &$rows, $children, $layout, $budget): void {
            if (++$visited > $budget) { throw new \InvalidArgumentException('Initial expanded layout budget exceeded.'); }
            $element = $layout->elements[$id];
            if (($element['type'] ?? null) === 'repeatable-group') {
                $key = (new FieldAddress($id, $scope))->key();
                $declarations[$key] = [];
                for ($i = 0; $i < $element['repeat']['min']; $i++) {
                    if (++$rows > $budget) { throw new \InvalidArgumentException('Initial row budget exceeded.'); }
                    $row = Uuid::create(); $declarations[$key][] = $row;
                    foreach ($children[$id] ?? [] as $child) { $walk($child, [...$scope, ['group' => $id, 'instance' => $row]]); }
                }
            } else {
                foreach ($children[$id] ?? [] as $child) { $walk($child, $scope); }
            }
        };
        foreach ($children[''] ?? [] as $id) { $walk($id, []); }
        return new self($elements, $declarations, $budget);
    }

    /** Evaluate a form validator once per deepest common lexical row scope. */
    public function referenceContexts(array $fields, int $budget = 10000): array
    {
        if ($fields === [] || !array_is_list($fields)) { throw new \InvalidArgumentException('Validator references are required.'); }
        $anchor = null; $scope = [];
        foreach ($fields as $field) {
            if (!is_string($field) || ($this->elements[$field]['type'] ?? null) !== 'field') { throw new \InvalidArgumentException('Unknown validator reference.'); }
            $candidate = $this->ancestors[$field];
            $common = min(count($scope), count($candidate));
            if (array_slice($scope, 0, $common) !== array_slice($candidate, 0, $common)) { throw new \InvalidArgumentException('Validator requires explicit cross-group aggregation.'); }
            if ($anchor === null || count($candidate) > count($scope)) { $anchor = $field; $scope = $candidate; }
        }
        return array_values(array_filter($this->addresses($budget), static fn (FieldAddress $address): bool => $address->field === $anchor));
    }

    /** Resolve an ordinary field reference in the lexical scope of one row. */
    public function resolve(FieldAddress $origin, string $field): FieldAddress
    {
        if (!$this->containsElement($origin) || ($this->elements[$field]['type'] ?? null) !== 'field') { throw new \InvalidArgumentException('Unknown reference context or field.'); }
        $required = $this->ancestors[$field];
        if (array_slice(array_column($origin->instances, 'group'), 0, count($required)) !== $required) {
            throw new \InvalidArgumentException('Field reference requires an explicit repeated scope.');
        }
        $target = new FieldAddress($field, array_slice($origin->instances, 0, count($required)));
        if (!$this->contains($target)) { throw new \InvalidArgumentException('Referenced instance is not declared.'); }
        return $target;
    }

    /** Expand controls in layout/row order, including controls omitted from input. */
    public function addresses(int $budget = 10000): array
    {
        return array_values(array_filter($this->elementAddresses($budget), fn (FieldAddress $address): bool => $this->elements[$address->field]['type'] === 'field'));
    }

    /** Include containers so rules can inherit activation through each row. */
    public function elementAddresses(int $budget = 10000): array
    {
        if ($budget < 1) { throw new \InvalidArgumentException('Invalid expansion budget.'); }
        $children = [];
        foreach ($this->elements as $id => $element) { $children[$element['parent_uuid'] ?? ''][] = $id; }
        $result = []; $visited = 0;
        $walk = function (string $id, array $parents) use (&$walk, &$result, &$visited, $budget, $children): void {
            if (++$visited > $budget) { throw new \InvalidArgumentException('Expanded layout budget exceeded.'); }
            $type = $this->elements[$id]['type'] ?? null;
            $result[] = new FieldAddress($id, $parents);
            if ($type === 'field') { return; }
            if ($type === 'repeatable-group') {
                $key = (new FieldAddress($id, $parents))->key();
                foreach ($this->rows[$key] as $row) {
                    foreach ($children[$id] ?? [] as $child) { $walk($child, [...$parents, ['group' => $id, 'instance' => $row]]); }
                }
            } else {
                foreach ($children[$id] ?? [] as $child) { $walk($child, $parents); }
            }
        };
        foreach ($children[''] ?? [] as $id) { $walk($id, []); }
        return $result;
    }

    /** Bind raw values without coercing a multivalue control into repeated rows. */
    public function bind(array $raw, int $budget = 10000): array
    {
        if (count($raw) > $budget) { throw new \InvalidArgumentException('Input budget exceeded.'); }
        foreach ($raw as $key => $value) {
            if (!is_string($key) || !$this->contains(FieldAddress::fromKey($key))) { throw new \InvalidArgumentException('Answer does not belong to a declared instance.'); }
        }
        $bound = [];
        foreach ($this->addresses($budget) as $address) { $key = $address->key(); $bound[$key] = $raw[$key] ?? null; }
        return $bound;
    }
}
