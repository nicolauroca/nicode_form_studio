<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Validation;

use Nicode\FormStudio\Contract\FieldTypeInterface;

/** Common final field checks for ordinary and instance-addressed values. */
final class FieldValueValidation
{
    public static function validate(FieldTypeInterface $provider, array $field, mixed $value, array $state, ?array $normalizationErrors = null): array
    {
        $config = array_replace($field['config'] ?? [], ['required' => $state['required']]);
        try {
            $value = $provider->normalize($value, $config);
            $errors = $normalizationErrors ?? $provider->validate($value, $config);
            if ($errors === [] && ($field['index'] ?? false) && ($field['persist'] ?? true)) { $errors = IndexLimits::validate($provider->indexType(), $value, $provider->multiple()); }
        } catch (\InvalidArgumentException) { $value = null; $errors = ['type']; }
        if ($provider->metadata()['datatype'] === 'selection' && $value !== null && $value !== []) {
            $options = array_column(array_filter($state['options'], static fn (array $option): bool => $option['enabled'] ?? true), 'value');
            foreach (is_array($value) ? $value : [$value] as $selected) {
                if (!in_array($selected, $options, true)) { $errors[] = 'option'; break; }
            }
        }
        return ['value' => $errors === [] ? $provider->serialize($value) : null, 'errors' => array_values(array_unique($errors))];
    }
}
