<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Rules;

use Nicode\FormStudio\Contract\RuleOperatorInterface;
use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Validation\Decimal;
use Nicode\FormStudio\Validation\SafePattern;

final readonly class CoreOperator implements RuleOperatorInterface
{
    public const IDS = ['equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with', 'in', 'not_in', 'empty', 'not_empty', 'selected', 'not_selected', 'greater', 'less', 'greater_equal', 'less_equal', 'between', 'before', 'after', 'pattern'];

    public function __construct(private string $identifier)
    {
        if (!in_array($identifier, self::IDS, true)) { throw new \InvalidArgumentException('Unknown core operator.'); }
    }
    public function id(): string { return $this->identifier; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['id' => $this->id(), 'version' => $this->version()]; }
    public function validateConfiguration(array $configuration, string $path): array
    {
        $datatype = $configuration['datatype'] ?? null;
        if (in_array($datatype, \Nicode\FormStudio\Validation\Temporal::TYPES, true) && !in_array($this->id(), ['empty', 'not_empty'], true)) {
            $value = $configuration['value'] ?? null;
            foreach (is_array($value) ? $value : [$value] as $operand) {
                if (\Nicode\FormStudio\Validation\Temporal::key($datatype, $operand) === null) { return [new Diagnostic('operator.value.temporal', $path, 'Invalid temporal operand.')]; }
            }
        }
        $value = $configuration['value'] ?? null;
        if (in_array($this->id(), ['in', 'not_in', 'between'], true) && (!is_array($value) || !array_is_list($value) || ($this->id() === 'between' && count($value) !== 2))) {
            return [new Diagnostic('operator.value.list', $path, 'Operator requires a value list; between requires two bounds.')];
        }
        if ($this->id() === 'pattern' && (!is_string($value) || !SafePattern::valid($value))) {
            return [new Diagnostic('operator.value.pattern', $path, 'Unsupported pattern.')];
        }
        if (in_array($datatype, ['integer', 'decimal'], true) && !in_array($this->id(), ['empty', 'not_empty'], true)) {
            $list = in_array($this->id(), ['in', 'not_in', 'between'], true);
            try {
                foreach ($list ? $value : [$value] as $operand) {
                    if ($operand === null && in_array($this->id(), ['equals', 'not_equals', 'in', 'not_in'], true)) { continue; }
                    Decimal::normalize($operand);
                }
                if ($this->id() === 'between' && Decimal::compare((string) $value[0], (string) $value[1]) > 0) {
                    return [new Diagnostic('operator.value.range', $path, 'Lower bound exceeds upper bound.')];
                }
            } catch (\InvalidArgumentException) {
                return [new Diagnostic('operator.value.numeric', $path, 'Expected an exact numeric operand.')];
            }
        }
        return [];
    }

    public function evaluate(mixed $left, mixed $right, string $datatype): bool
    {
        $empty = $left === null || $left === '' || $left === [];
        if ($this->id() === 'empty') { return $empty; }
        if ($this->id() === 'not_empty') { return !$empty; }
        if (!$empty && in_array($datatype, \Nicode\FormStudio\Validation\Temporal::TYPES, true)) {
            $left = \Nicode\FormStudio\Validation\Temporal::key($datatype, $left);
            if ($left === null) { return false; }
            $operands = [];
            foreach (is_array($right) ? $right : [$right] as $operand) {
                $key = \Nicode\FormStudio\Validation\Temporal::key($datatype, $operand);
                if ($key === null) { return false; }
                $operands[] = $key;
            }
            $right = is_array($right) ? $operands : $operands[0];
        }
        if ($this->id() === 'equals') { return $this->equal($left, $right, $datatype); }
        if ($this->id() === 'not_equals') { return !$this->equal($left, $right, $datatype); }
        if ($empty) { return false; }
        return match ($this->id()) {
            'contains' => is_string($left) && is_string($right) && str_contains($left, $right),
            'not_contains' => is_string($left) && is_string($right) && !str_contains($left, $right),
            'starts_with' => is_string($left) && is_string($right) && str_starts_with($left, $right),
            'ends_with' => is_string($left) && is_string($right) && str_ends_with($left, $right),
            'in' => is_array($right) && $this->member($left, $right, $datatype),
            'not_in' => is_array($right) && !$this->member($left, $right, $datatype),
            'selected' => is_array($left) ? in_array($right, $left, true) : $left === $right,
            'not_selected' => is_array($left) ? !in_array($right, $left, true) : $left !== $right,
            'greater', 'after' => $this->compare($left, $right, $datatype) > 0,
            'less', 'before' => $this->compare($left, $right, $datatype) < 0,
            'greater_equal' => $this->compare($left, $right, $datatype) >= 0,
            'less_equal' => $this->compare($left, $right, $datatype) <= 0,
            'between' => is_array($right) && count($right) === 2 && $this->compare($left, $right[0], $datatype) >= 0 && $this->compare($left, $right[1], $datatype) <= 0,
            'pattern' => is_string($left) && is_string($right) && SafePattern::matches($right, $left),
        };
    }

    private function member(mixed $left, array $values, string $datatype): bool
    {
        foreach ($values as $value) { if ($this->equal($left, $value, $datatype)) { return true; } }
        return false;
    }
    private function equal(mixed $a, mixed $b, string $datatype): bool
    {
        if (in_array($datatype, ['integer', 'decimal'], true) && $a !== null && $b !== null) {
            return Decimal::compare((string) $a, (string) $b) === 0;
        }
        return $a === $b;
    }
    private function compare(mixed $a, mixed $b, string $datatype): int
    {
        if ($a === null || $b === null || is_array($a) || is_array($b)) { throw new \InvalidArgumentException('Invalid comparison operands.'); }
        return in_array($datatype, ['integer', 'decimal'], true) ? Decimal::compare((string) $a, (string) $b) : strcmp((string) $a, (string) $b);
    }
}
