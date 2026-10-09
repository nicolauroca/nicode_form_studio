<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Rules;

use Nicode\FormStudio\Registry\RuleOperatorRegistry;

final readonly class ConditionEvaluator
{
    public function __construct(private RuleOperatorRegistry $operators) {}

    public function matches(array $condition, array $values, array $datatypes, int $depth = 0): bool
    {
        if ($depth > 64) { throw new \DomainException('Condition nesting exceeds safe depth.'); }
        if (isset($condition['group'])) {
            if (!in_array($condition['group'], ['AND', 'OR'], true) || empty($condition['children'])) { throw new \DomainException('Invalid condition group.'); }
            $and = $condition['group'] === 'AND';
            foreach ($condition['children'] as $child) {
                $result = $this->matches($child, $values, $datatypes, $depth + 1);
                if ($and && !$result) { return false; }
                if (!$and && $result) { return true; }
            }
            return $and;
        }
        $field = $condition['field'];
        if (!isset($datatypes[$field])) { throw new \DomainException('Unknown condition field.'); }
        return $this->operators->get($condition['operator'])->evaluate($values[$field] ?? null, $condition['value'] ?? null, $datatypes[$field]);
    }
}
