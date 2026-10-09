<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Validation;

use Nicode\FormStudio\Domain\FormSpec;
use Nicode\FormStudio\Registry\FieldTypeRegistry;
use Nicode\FormStudio\Rules\RuleEngine;
use Nicode\FormStudio\Registry\ValidatorRegistry;

/** Raw keys are field UUIDs. Unknown keys never enter the accepted payload. */
final readonly class ValidationEngine
{
    public function __construct(private FieldTypeRegistry $fields, private RuleEngine $rules, private ?ValidatorRegistry $validators = null) {}

    public function validate(FormSpec $spec, array $raw, array $trustedDefaults = [], array $trustedContext = []): ValidationResult
    {
        $definition = $spec->toArray();
        $normalized = []; $normalizationErrors = []; $initialDefaults = [];
        foreach ($definition['fields'] as $field) {
            $uuid = $field['uuid'];
            $input = SubmittedFieldValue::normalize($this->fields->get($field['type']), $field, $uuid, $raw, $trustedDefaults, $trustedContext);
            $normalized[$uuid] = $input['value'];
            if ($input['errors'] !== []) { $normalizationErrors[$uuid] = $input['errors']; }
            if ($input['initial_default']) { $initialDefaults[$uuid] = true; }
        }
        $result = $this->rules->evaluate($spec, $normalized, $trustedContext, $initialDefaults);
        $accepted = []; $errors = [];
        foreach ($definition['fields'] as $field) {
            $uuid = $field['uuid']; $state = $result->states[$uuid];
            if (!$state['active']) { continue; }
            $checked = FieldValueValidation::validate($this->fields->get($field['type']), $field, $result->values[$uuid] ?? null, $state, $normalizationErrors[$uuid] ?? $result->normalizationErrors[$uuid] ?? null);
            if ($checked['errors'] !== []) { $errors[$uuid] = $checked['errors']; }
            else { $accepted[$uuid] = $checked['value']; }
        }
        $validators = $definition['validators'] ?? [];
        $datatypes = [];
        foreach ($definition['fields'] as $field) {
            $datatypes[$field['uuid']] = $this->fields->get($field['type'])->metadata()['datatype'];
            if ($result->states[$field['uuid']]['active']) { array_push($validators, ...($field['validators'] ?? [])); }
        }
        foreach ($validators as $validator) {
            if ($this->validators === null) { throw new \DomainException('Validator registry is required.'); }
            foreach ($this->validators->get($validator['type'])->validate($accepted, $validator['config'] ?? [], $datatypes) as $violation) {
                foreach ($violation->fields as $uuid) { $errors[$uuid][] = $violation->code; }
            }
        }
        return new ValidationResult($accepted, $errors, $result);
    }

    /** Internal repeated submission validation; transport and storage integration is separate. */
    public function validateInstances(FormSpec $spec, array $declarations, array $raw, array $trustedDefaults = [], array $trustedContext = [], int $budget = 10000): ValidationResult
    {
        $definition = $spec->toArray();
        $instances = new \Nicode\FormStudio\Domain\RepeatedInstances($definition['elements'], $declarations, $budget);
        $bound = $instances->bind($raw, $budget);
        // Trusted integration data must also identify an actual declared control.
        $instances->bind($trustedDefaults, $budget);
        $fields = array_column($definition['fields'], null, 'uuid');
        $normalized = []; $normalizationErrors = []; $initialDefaults = []; $datatypes = [];
        foreach ($fields as $uuid => $field) { $datatypes[$uuid] = $this->fields->get($field['type'])->metadata()['datatype']; }
        $addresses = $instances->addresses($budget);
        foreach ($addresses as $address) {
            $key = $address->key(); $field = $fields[$address->field] ?? null;
            if ($field === null) { throw new \InvalidArgumentException('Missing field definition.'); }
            $input = SubmittedFieldValue::normalize($this->fields->get($field['type']), $field, $key, $bound, $trustedDefaults, $trustedContext);
            $normalized[$key] = $input['value'];
            if ($input['errors'] !== []) { $normalizationErrors[$key] = $input['errors']; }
            if ($input['initial_default']) { $initialDefaults[$key] = true; }
        }
        $rules = $this->rules->evaluateInstances($spec, $declarations, $normalized, $trustedContext, $initialDefaults, $budget);
        $checked = (new AddressedFieldValidator($this->fields))->validate($definition['fields'], $instances, $rules, $normalizationErrors, $budget);
        $errors = $checked->errors;
        foreach ($instances->minimumErrors() as $key => $codes) {
            if ($rules->states[$key]['active']) { $errors[$key] = $codes; }
        }
        $calls = 0;
        $validate = function (array $validator, \Nicode\FormStudio\Domain\FieldAddress $origin) use ($instances, $checked, $datatypes, &$errors, &$calls, $budget): void {
            if (++$calls > $budget) { throw new \InvalidArgumentException('Expanded validator budget exceeded.'); }
            if ($this->validators === null) { throw new \DomainException('Validator registry is required.'); }
            foreach (ScopedValidator::validate($this->validators->get($validator['type']), $validator['config'] ?? [], $origin, $instances, $checked->values, $datatypes) as $violation) {
                foreach ($violation->fields as $key) { $errors[$key][] = $violation->code; }
            }
        };
        foreach ($addresses as $address) {
            if (!$rules->states[$address->key()]['active']) { continue; }
            foreach ($fields[$address->field]['validators'] ?? [] as $validator) { $validate($validator, $address); }
        }
        foreach ($definition['validators'] ?? [] as $validator) {
            foreach ($instances->referenceContexts($validator['config']['fields'] ?? [], $budget) as $origin) { $validate($validator, $origin); }
        }
        return new ValidationResult($checked->values, array_map(static fn (array $codes): array => array_values(array_unique($codes)), $errors), $rules);
    }
}
