<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Field;

use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Registry\FieldTypeRegistry;

/** Initial presentation values; query parameters never become server authority. */
final class Prefill
{
    public static function normalizeInitial(\Nicode\FormStudio\Contract\FieldTypeInterface $provider, array $field, mixed $value): mixed
    {
        $config = $field['config'] ?? [];
        try {
            $value = $provider->normalize($value, $config);
            if (isset($field['prefill']) && $provider->validate($value, array_replace($config, ['required' => false])) !== []) { return null; }
            return $value;
        } catch (\InvalidArgumentException $error) {
            if (!isset($field['prefill'])) { throw $error; }
            return null;
        }
    }
    public static function validate(array $field, array $fields, FieldTypeRegistry $types, string $path): array
    {
        if (!array_key_exists('prefill', $field)) { return []; }
        if (!is_string($field['type'] ?? null) || !$types->has($field['type'])) { return []; }
        $prefill = $field['prefill']; $type = $types->get($field['type']);
        $invalid = static fn (string $message): array => [new Diagnostic('field.prefill', $path . '/prefill', $message)];
        if (!is_array($prefill) || !($type->metadata()['prefill'] ?? false)) { return $invalid('Field does not support this prefill configuration.'); }
        $mode = $prefill['type'] ?? null;
        $keys = match ($mode) { 'constant' => ['type', 'value'], 'user' => ['type', 'property'], 'context', 'query' => ['type', 'key'], 'field' => ['type', 'field'], 'source' => ['type'], default => [] };
        if ($keys === [] || array_diff(array_keys($prefill), $keys) !== []) { return $invalid('Unsupported prefill configuration.'); }
        if ($mode === 'constant') {
            if (!array_key_exists('value', $prefill)) { return $invalid('Constant prefill requires a value.'); }
            try {
                $value = $type->normalize($prefill['value'], $field['config'] ?? []);
                if ($type->validate($value, $field['config'] ?? []) !== []) { return $invalid('Invalid constant prefill.'); }
                if (($field['index'] ?? false) && ($field['persist'] ?? true) && \Nicode\FormStudio\Validation\IndexLimits::validate($type->indexType(), $value, $type->multiple()) !== []) { return $invalid('Constant prefill exceeds the supported search index limits.'); }
            }
            catch (\InvalidArgumentException) { return $invalid('Invalid constant prefill.'); }
        }
        if ($mode === 'user' && !in_array($prefill['property'] ?? null, ['id', 'name', 'username', 'email'], true)) { return $invalid('Select an approved Joomla user property.'); }
        if (in_array($mode, ['context', 'query'], true) && (!is_string($prefill['key'] ?? null) || preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,63}$/D', $prefill['key']) !== 1)) { return $invalid('An explicit context or query key is required.'); }
        if ($mode === 'query' && self::authoritative($field)) { return $invalid('Query prefill is only permitted for visitor-editable fields.'); }
        if ($mode === 'field') {
            $reference = $prefill['field'] ?? null;
            if (!is_string($reference) || !isset($fields[$reference]) || in_array($fields[$reference]['type'], ['password', 'file', 'multiple-files'], true)) { return $invalid('Select an existing non-file, non-password field.'); }
            if ($types->has($fields[$reference]['type']) && ($type->metadata()['datatype'] !== $types->get($fields[$reference]['type'])->metadata()['datatype'] || $type->multiple() !== $types->get($fields[$reference]['type'])->multiple())) { return $invalid('Prefill fields require compatible datatypes and multiplicity.'); }
            if (($fields[$reference]['sensitive'] ?? false) && !($field['sensitive'] ?? false)) { return $invalid('Sensitive prefill cannot flow to a non-sensitive field.'); }
        }
        if ($mode === 'source' && ($type->metadata()['datatype'] ?? '') !== 'selection') { return $invalid('Data source prefill requires a selection field.'); }
        return [];
    }
    public static function authoritative(array $field): bool { return ($field['config']['readonly'] ?? false) || in_array($field['type'], ['system', 'calculated'], true); }
    public static function value(array $field, array $context): mixed
    {
        $prefill = $field['prefill'] ?? []; $fallback = $field['config']['default'] ?? null;
        return match ($prefill['type'] ?? null) {
            'constant' => $prefill['value'] ?? null,
            'user' => ($context['user_id'] ?? 0) > 0 ? ($context['user_properties'][$prefill['property']] ?? null) : null,
            'context' => $context['prefill_context'][$prefill['key']] ?? null,
            'query' => $context['prefill_query'][$prefill['key']] ?? null,
            default => $fallback,
        };
    }
}
