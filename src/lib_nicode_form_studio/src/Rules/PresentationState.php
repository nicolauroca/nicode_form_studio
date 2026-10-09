<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Rules;

use Nicode\FormStudio\Domain\{FormSpec, RepeatedInstances};
use Nicode\FormStudio\Field\Prefill;
use Nicode\FormStudio\Registry\FieldTypeRegistry;

/** Shared initial/retry presentation policy for public forms, preview and rows. */
final readonly class PresentationState
{
    public function __construct(private FieldTypeRegistry $types, private RuleEngine $rules) {}

    public function evaluate(FormSpec $spec, array $context = [], array $trusted = [], ?array $submitted = null, bool $reset = false): RuleResult
    {
        $fields = [];
        foreach ($spec->toArray()['fields'] as $field) { $fields[$field['uuid']] = $field; }
        [$values, $defaults] = $this->values($fields, $context, $trusted, $submitted, $reset);
        return $this->rules->evaluate($spec, $values, $context, $defaults);
    }

    public function evaluateInstances(FormSpec $spec, array $declarations, array $context = [], array $trusted = [], ?array $submitted = null, int $budget = 10000, bool $reset = false): RuleResult
    {
        $data = $spec->toArray();
        $instances = new RepeatedInstances($data['elements'], $declarations, $budget);
        $instances->bind($trusted, $budget);
        if ($submitted !== null) { $instances->bind($submitted, $budget); }
        $definitions = array_column($data['fields'], null, 'uuid'); $fields = [];
        foreach ($instances->addresses($budget) as $address) {
            $fields[$address->key()] = $definitions[$address->field] ?? throw new \InvalidArgumentException('Missing field definition.');
        }
        [$values, $defaults] = $this->values($fields, $context, $trusted, $submitted, $reset);
        return $this->rules->evaluateInstances($spec, $declarations, $values, $context, $defaults, $budget);
    }

    private function values(array $fields, array $context, array $trusted, ?array $submitted, bool $reset): array
    {
        $values = []; $defaults = [];
        foreach ($fields as $key => $field) {
            $authoritative = Prefill::authoritative($field);
            $initial = $submitted === null || ($reset && !array_key_exists($key, $submitted));
            if (($initial || $authoritative) && !array_key_exists($key, $trusted)) { $defaults[$key] = true; }
            $value = !$authoritative && !$initial ? ($submitted[$key] ?? null) : (array_key_exists($key, $trusted) ? $trusted[$key] : Prefill::value($field, $context));
            if (in_array($field['type'], ['password', 'file', 'multiple-files'], true)) { $value = null; }
            $provider = $this->types->get($field['type']);
            try { $values[$key] = $initial ? Prefill::normalizeInitial($provider, $field, $value) : $provider->normalize($value, $field['config'] ?? []); }
            catch (\InvalidArgumentException $error) {
                if ($initial && !isset($field['prefill'])) { throw $error; }
                $values[$key] = null;
            }
        }
        return [$values, $defaults];
    }
}
