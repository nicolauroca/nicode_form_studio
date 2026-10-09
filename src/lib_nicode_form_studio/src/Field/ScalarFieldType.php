<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Field;

use Nicode\FormStudio\Contract\FieldTypeInterface;
use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Validation\Decimal;
use Nicode\FormStudio\Validation\SafePattern;
use Nicode\FormStudio\Validation\Temporal;

final readonly class ScalarFieldType implements FieldTypeInterface
{
    public function __construct(private string $identifier, private string $datatype, private ?string $index, private bool $isMultiple = false)
    {
    }

    public function id(): string { return $this->identifier; }
    public function version(): string { return '1.0.0'; }
    public function indexType(): ?string { return $this->index; }
    public function multiple(): bool { return $this->isMultiple; }
    public function serialize(mixed $value): mixed { return $value; }

    public function metadata(): array
    {
        $properties = CommonConfiguration::properties();
        if ($this->datatype === 'text') {
            $properties += ['placeholder' => ['type' => 'string'], 'min_length' => ['type' => 'integer', 'minimum' => 0], 'max_length' => ['type' => 'integer', 'minimum' => 0], 'pattern' => ['type' => 'string']];
            if ($this->id() !== 'password') { $properties['trim'] = ['type' => 'boolean', 'default' => true]; }
        }
        if (in_array($this->datatype, ['text', 'integer', 'decimal'], true)) {
            $properties += ['autocomplete' => ['type' => 'string'], 'inputmode' => ['type' => 'string', 'enum' => ['', 'none', 'text', 'decimal', 'numeric', 'tel', 'search', 'email', 'url']]];
        }
        if (in_array($this->datatype, ['integer', 'decimal', ...Temporal::TYPES], true)) {
            $properties += ['min' => ['type' => 'string'], 'max' => ['type' => 'string']];
        }
        if (in_array($this->datatype, ['integer', 'decimal'], true)) {
            $properties += ['step' => ['type' => 'string'], 'precision' => ['type' => 'integer', 'minimum' => 1], 'scale' => ['type' => 'integer', 'minimum' => 0]];
        }
        if ($this->id() === 'range') {
            foreach (CommonConfiguration::effective('range', []) as $key => $value) { $properties[$key]['default'] = $value; }
        }
        if ($this->isMultiple) {
            $properties += ['min_selections' => ['type' => 'integer', 'minimum' => 0], 'max_selections' => ['type' => 'integer', 'minimum' => 0]];
        }
        return [
            'id' => $this->id(), 'version' => $this->version(), 'datatype' => $this->datatype,
            'label_key' => 'COM_NICODE_FORM_STUDIO_FIELD_TYPE_' . strtoupper(str_replace('-', '_', $this->id())),
            'index_type' => $this->index, 'multiple' => $this->isMultiple,
            'category' => $this->datatype, 'renderer' => $this->id(),
            'prefill' => !in_array($this->id(), ['password'], true),
            'readonly' => true, 'disabled' => true, 'assets' => [],
            'operators' => ['equals', 'not_equals', 'empty', 'not_empty', 'in', 'not_in', ...match ($this->datatype) {
                'integer', 'decimal' => ['greater', 'less', 'greater_equal', 'less_equal', 'between'],
                'date', 'time', 'datetime', 'month', 'week' => ['before', 'after', 'between'],
                'selection' => ['selected', 'not_selected'],
                'text' => ['contains', 'not_contains', 'starts_with', 'ends_with', 'pattern'],
                default => [],
            }],
            'configuration_schema' => [
                'type' => 'object',
                'properties' => $properties,
            ],
        ];
    }

    public function validateConfiguration(array $configuration, string $path): array
    {
        $configuration = CommonConfiguration::effective($this->id(), $configuration);
        $errors = [];
        foreach (['min_length', 'max_length', 'min_selections', 'max_selections', 'precision', 'scale'] as $key) {
            if (isset($configuration[$key]) && (!is_int($configuration[$key]) || $configuration[$key] < ($key === 'precision' ? 1 : 0))) {
                $errors[] = new Diagnostic('field.configuration.integer', "$path/$key", 'Expected a non-negative integer.');
            }
        }
        foreach ([['min_length', 'max_length'], ['min_selections', 'max_selections'], ['scale', 'precision']] as [$min, $max]) {
            if (isset($configuration[$min], $configuration[$max]) && $configuration[$min] > $configuration[$max]) {
                $errors[] = new Diagnostic('field.configuration.range', $path, 'Minimum exceeds maximum.');
            }
        }
        if (isset($configuration['pattern']) && (!is_string($configuration['pattern']) || !SafePattern::valid($configuration['pattern']))) {
            $errors[] = new Diagnostic('field.configuration.pattern', "$path/pattern", 'Unsupported safe pattern.');
        }
        if (in_array($this->datatype, ['integer', 'decimal'], true)) {
            try {
                foreach (['min', 'max', 'step'] as $key) {
                    if (isset($configuration[$key])) {
                        $number = Decimal::normalize($configuration[$key]);
                        if ($this->datatype === 'integer' && str_contains($number, '.')) { throw new \InvalidArgumentException('Integer bounds and step must be whole numbers.'); }
                    }
                }
                if (isset($configuration['min'], $configuration['max']) && Decimal::compare((string) $configuration['min'], (string) $configuration['max']) > 0) {
                    throw new \InvalidArgumentException('Minimum exceeds maximum.');
                }
                if (isset($configuration['step']) && Decimal::compare((string) $configuration['step'], '0') <= 0) {
                    throw new \InvalidArgumentException('Step must be positive.');
                }
            } catch (\InvalidArgumentException $error) {
                $errors[] = new Diagnostic('field.configuration.numeric', $path, $error->getMessage());
            }
        }
        if (in_array($this->datatype, Temporal::TYPES, true)) {
            $bounds = [];
            foreach (['min', 'max'] as $key) {
                if (isset($configuration[$key])) {
                    $bounds[$key] = Temporal::key($this->datatype, $configuration[$key]);
                    if ($bounds[$key] === null) { $errors[] = new Diagnostic('field.configuration.temporal', "$path/$key", 'Invalid temporal bound.'); }
                }
            }
            if (isset($bounds['min'], $bounds['max']) && strcmp($bounds['min'], $bounds['max']) > 0) { $errors[] = new Diagnostic('field.configuration.range', $path, 'Minimum exceeds maximum.'); }
        }
        return $errors;
    }

    public function normalize(mixed $value, array $configuration): mixed
    {
        if ($this->isMultiple) {
            if ($value === null || $value === '') { return []; }
            if (!is_array($value) || !array_is_list($value)) { throw new \InvalidArgumentException('Expected a list.'); }
            $result = [];
            foreach ($value as $item) {
                if (!is_string($item) && !is_int($item)) { throw new \InvalidArgumentException('Invalid selection.'); }
                $result[] = (string) $item;
            }
            return array_values(array_unique($result, SORT_STRING));
        }
        if ($value === null || $value === '') { return null; }
        if ($this->datatype === 'boolean') {
            return match ($value) {
                true, 1, '1', 'true', 'on' => true,
                false, 0, '0', 'false', 'off' => false,
                default => throw new \InvalidArgumentException('Invalid boolean.'),
            };
        }
        if (!is_string($value) && !is_int($value)) { throw new \InvalidArgumentException('Expected scalar text.'); }
        $value = (string) $value;
        if (!mb_check_encoding($value, 'UTF-8')) { throw new \InvalidArgumentException('Invalid UTF-8.'); }
        if ($this->datatype !== 'selection' && $this->id() !== 'password' && ($configuration['trim'] ?? true)) { $value = trim($value); }
        if ($value === '') { return null; }
        if ($this->datatype === 'decimal') { return Decimal::normalize($value); }
        if ($this->datatype === 'integer') {
            $value = Decimal::normalize($value);
            if (str_contains($value, '.') || filter_var($value, FILTER_VALIDATE_INT) === false) {
                throw new \InvalidArgumentException('Invalid integer or outside signed platform range.');
            }
            return (int) $value;
        }
        return $value;
    }

    public function validate(mixed $value, array $configuration): array
    {
        $configuration = CommonConfiguration::effective($this->id(), $configuration);
        $empty = $value === null || $value === '' || $value === [];
        if (($configuration['required'] ?? false) && ($empty || ($this->datatype === 'boolean' && $value !== true))) { return ['required']; }
        if ($empty) { return []; }
        $errors = [];
        if ($this->isMultiple) {
            if (!is_array($value)) { return ['type']; }
            foreach (['min_selections' => -1, 'max_selections' => 1] as $key => $direction) {
                if (isset($configuration[$key]) && (count($value) <=> $configuration[$key]) === $direction) { $errors[] = $key; }
            }
            return $errors;
        }
        if ($this->datatype === 'boolean') { return is_bool($value) ? [] : ['type']; }
        $text = (string) $value;
        foreach (['min_length' => -1, 'max_length' => 1] as $key => $direction) {
            if (isset($configuration[$key]) && (mb_strlen($text, 'UTF-8') <=> $configuration[$key]) === $direction) { $errors[] = $key; }
        }
        if (isset($configuration['pattern']) && !SafePattern::matches($configuration['pattern'], $text)) { $errors[] = 'pattern'; }
        if ($this->id() === 'email' && (!filter_var($text, FILTER_VALIDATE_EMAIL) || strpbrk($text, "\r\n") !== false)) { $errors[] = 'email'; }
        if ($this->id() === 'url' && (!filter_var($text, FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($text, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true))) { $errors[] = 'url'; }
        if ($this->id() === 'color' && preg_match('/^#[0-9a-fA-F]{6}$/D', $text) !== 1) { $errors[] = 'color'; }
        if (in_array($this->datatype, ['integer', 'decimal'], true)) {
            foreach (['min' => -1, 'max' => 1] as $key => $direction) {
                if (isset($configuration[$key]) && Decimal::compare($text, (string) $configuration[$key]) === $direction) { $errors[] = $key; }
            }
            if (isset($configuration['step']) && !Decimal::stepMatches($text, (string) $configuration['step'], (string) ($configuration['min'] ?? '0'))) { $errors[] = 'step'; }
            if (isset($configuration['scale']) && Decimal::scale($text) > $configuration['scale']) { $errors[] = 'scale'; }
            $digits = strlen(str_replace(['-', '.'], '', Decimal::normalize($text)));
            if (isset($configuration['precision']) && $digits > $configuration['precision']) { $errors[] = 'precision'; }
        }
        if (in_array($this->datatype, Temporal::TYPES, true)) {
            $keyValue = Temporal::key($this->datatype, $text);
            if ($keyValue === null) { $errors[] = $this->datatype; return $errors; }
            foreach (['min' => -1, 'max' => 1] as $key => $direction) {
                if (isset($configuration[$key])) {
                    $bound = Temporal::key($this->datatype, $configuration[$key]);
                    if ($bound === null || (strcmp($keyValue, $bound) <=> 0) === $direction) { $errors[] = $key; }
                }
            }
        }
        return $errors;
    }
}
