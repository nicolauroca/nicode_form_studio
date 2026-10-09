<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Validation;

use Nicode\FormStudio\Contract\FieldTypeInterface;
use Nicode\FormStudio\Field\Prefill;

/** Shared submission authority policy, independent of the field's address format. */
final class SubmittedFieldValue
{
    public static function normalize(FieldTypeInterface $provider, array $field, string $key, array $raw, array $trustedDefaults, array $trustedContext): array
    {
        $value = $raw[$key] ?? null; $initialDefault = false;
        if (in_array($field['type'], ['file', 'multiple-files'], true)) {
            $value = $trustedDefaults[$key] ?? null;
        } elseif (Prefill::authoritative($field)) {
            $initialDefault = !array_key_exists($key, $trustedDefaults);
            $value = $initialDefault ? Prefill::value($field, $trustedContext) : $trustedDefaults[$key];
        }
        try { return ['value' => $provider->normalize($value, $field['config'] ?? []), 'errors' => [], 'initial_default' => $initialDefault]; }
        catch (\InvalidArgumentException) { return ['value' => null, 'errors' => ['type'], 'initial_default' => $initialDefault]; }
    }
}
