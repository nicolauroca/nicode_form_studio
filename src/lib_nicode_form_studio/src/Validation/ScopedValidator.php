<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Validation;

use Nicode\FormStudio\Contract\ValidatorInterface;
use Nicode\FormStudio\Domain\{FieldAddress, RepeatedInstances};

/** Adapt declared cross-field references to one repeated row without sharing sibling values. */
final class ScopedValidator
{
    public static function validate(ValidatorInterface $provider, array $configuration, FieldAddress $origin, RepeatedInstances $instances, array $accepted, array $datatypes): array
    {
        $references = $configuration['fields'] ?? null;
        if (!is_array($references) || !array_is_list($references) || $references === [] || $provider->validateConfiguration($configuration, '/validator') !== []) { throw new \InvalidArgumentException('Invalid scoped validator configuration.'); }
        $addresses = []; $values = []; $types = [];
        foreach ($references as $field) {
            if (!is_string($field) || !is_string($datatypes[$field] ?? null)) { throw new \InvalidArgumentException('Unknown scoped validator field.'); }
            $key = $instances->resolve($origin, $field)->key(); $addresses[$field] = $key; $types[$field] = $datatypes[$field];
            if (array_key_exists($key, $accepted)) { $values[$field] = $accepted[$key]; }
        }
        $violations = [];
        foreach ($provider->validate($values, $configuration, $types) as $violation) {
            if (!$violation instanceof Violation) { throw new \DomainException('Invalid validator result.'); }
            $fields = [];
            foreach ($violation->fields as $field) {
                if (!is_string($field) || !isset($addresses[$field]) || !array_key_exists($field, $values)) { throw new \DomainException('Validator returned an undeclared or inactive field.'); }
                $fields[] = $addresses[$field];
            }
            $violations[] = new Violation($violation->code, $fields);
        }
        return $violations;
    }
}
