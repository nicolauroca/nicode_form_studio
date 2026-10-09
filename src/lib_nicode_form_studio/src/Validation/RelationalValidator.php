<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Validation;

use Nicode\FormStudio\Contract\ValidatorInterface;
use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Rules\CoreOperator;

final readonly class RelationalValidator implements ValidatorInterface
{
    public const IDS = ['equals', 'not_equals', 'greater', 'less', 'greater_equal', 'less_equal', 'before', 'after', 'at_least_one', 'exactly_n', 'range', 'confirmation'];
    public function __construct(private string $identifier)
    {
        if (!in_array($identifier, self::IDS, true)) { throw new \InvalidArgumentException('Unknown relational validator.'); }
    }
    public function id(): string { return $this->identifier; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array
    {
        $many = in_array($this->id(), ['at_least_one', 'exactly_n'], true);
        return ['id' => $this->id(), 'version' => $this->version(), 'scope' => 'cross-field',
            'configuration_schema' => ['type' => 'object', 'required' => $this->id() === 'exactly_n' ? ['fields', 'count'] : ['fields'], 'properties' => [
                'fields' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'field-uuid'], 'uniqueItems' => true, 'minItems' => $many ? 1 : 2, ...($many ? [] : ['maxItems' => 2])],
                ...($this->id() === 'exactly_n' ? ['count' => ['type' => 'integer', 'minimum' => 0]] : []),
            ]], 'assets' => []];
    }
    public function validateConfiguration(array $configuration, string $path): array
    {
        $fields = $configuration['fields'] ?? null;
        if (!is_array($fields) || !array_is_list($fields) || count($fields) < 1 || count(array_filter($fields, is_string(...))) !== count($fields) || count(array_unique($fields)) !== count($fields)) {
            return [new Diagnostic('validator.fields', $path, 'Validator requires distinct field references.')];
        }
        if (!in_array($this->id(), ['at_least_one', 'exactly_n'], true) && count($fields) !== 2) {
            return [new Diagnostic('validator.arity', $path, 'Validator requires exactly two fields.')];
        }
        if ($this->id() === 'exactly_n' && (!is_int($configuration['count'] ?? null) || $configuration['count'] < 0 || $configuration['count'] > count($fields))) {
            return [new Diagnostic('validator.count', $path, 'Invalid number of required values.')];
        }
        return [];
    }
    /** Validate resolved field metadata without applying core semantics to custom validators. */
    public function validateFields(array $fields, string $path): array
    {
        if (in_array($this->id(), ['at_least_one', 'exactly_n'], true) || count($fields) !== 2) { return []; }
        [$left, $right] = $fields;
        $numeric = in_array($left['datatype'], ['integer', 'decimal'], true) && in_array($right['datatype'], ['integer', 'decimal'], true);
        $temporal = $left['datatype'] === $right['datatype'] && in_array($left['datatype'], Temporal::TYPES, true);
        $compatible = ($left['datatype'] === $right['datatype'] || $numeric)
            && $left['multiple'] === $right['multiple'] && $left['datatype'] !== 'file';
        if (in_array($this->id(), ['before', 'after'], true)) { $compatible = $compatible && $temporal && !$left['multiple']; }
        elseif (!in_array($this->id(), ['equals', 'not_equals', 'confirmation'], true)) { $compatible = $compatible && ($numeric || $temporal) && !$left['multiple']; }
        return $compatible ? [] : [new Diagnostic('validator.compatibility', $path, 'Select fields with compatible datatypes and multiplicity for this comparison.')];
    }

    public function validate(array $values, array $configuration, array $datatypes): array
    {
        $fields = $configuration['fields'];
        $active = array_values(array_filter($fields, static fn (string $uuid): bool => array_key_exists($uuid, $values)));
        if ($active === []) { return []; }
        $present = static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [] && $value !== false;
        if (in_array($this->id(), ['at_least_one', 'exactly_n'], true)) {
            $count = count(array_filter(array_intersect_key($values, array_flip($active)), $present));
            $valid = $this->id() === 'at_least_one' ? $count >= 1 : $count === $configuration['count'];
        } else {
            // Inactive fields cannot introduce an invisible cross-field error.
            if (count($active) !== count($fields)) { return []; }
            [$left, $right] = $fields;
            $empty = static fn (mixed $value): bool => $value === null || $value === '' || $value === [];
            if ($empty($values[$left]) || $empty($values[$right])) { return []; }
            $operator = match ($this->id()) { 'confirmation' => 'equals', 'range' => 'less_equal', default => $this->id() };
            $valid = (new CoreOperator($operator))->evaluate($values[$left], $values[$right], $datatypes[$left]);
        }
        return $valid ? [] : [new Violation('cross.' . $this->id(), $active)];
    }
}
