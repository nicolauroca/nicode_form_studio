<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Validation;

use Nicode\FormStudio\Domain\RepeatedInstances;
use Nicode\FormStudio\Registry\FieldTypeRegistry;
use Nicode\FormStudio\Rules\RuleResult;

/** Final field phase only: input states must already contain authoritative per-instance rule results. */
final readonly class AddressedFieldValidator
{
    public function __construct(private FieldTypeRegistry $types) {}

    public function validate(array $fields, RepeatedInstances $instances, RuleResult $rules, array $normalizationErrors = [], int $budget = 10000): ValidationResult
    {
        $definitions = [];
        foreach ($fields as $field) {
            if (!is_string($field['uuid'] ?? null) || isset($definitions[$field['uuid']])) { throw new \InvalidArgumentException('Invalid field definitions.'); }
            $definitions[$field['uuid']] = $field;
        }
        $accepted = []; $errors = [];
        foreach ($instances->addresses($budget) as $address) {
            $key = $address->key(); $field = $definitions[$address->field] ?? null; $state = $rules->states[$key] ?? null;
            if ($field === null || !is_array($state) || !is_bool($state['active'] ?? null) || !is_bool($state['required'] ?? null) || !is_array($state['options'] ?? null)) { throw new \InvalidArgumentException('Missing or invalid instance state.'); }
            if (!$state['active']) { continue; }
            $result = FieldValueValidation::validate($this->types->get($field['type']), $field, $rules->values[$key] ?? null, $state, $normalizationErrors[$key] ?? $rules->normalizationErrors[$key] ?? null);
            if ($result['errors'] !== []) { $errors[$key] = $result['errors']; }
            else { $accepted[$key] = $result['value']; }
        }
        return new ValidationResult($accepted, $errors, $rules);
    }
}
