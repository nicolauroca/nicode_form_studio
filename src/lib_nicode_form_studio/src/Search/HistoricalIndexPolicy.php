<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Search;

use Nicode\FormStudio\Domain\FormSpec;

/** Derived search configuration only; never a replacement executable snapshot. */
final class HistoricalIndexPolicy
{
    public static function apply(FormSpec $historical, FormSpec $current): FormSpec
    {
        $definition = $historical->toArray(); $active = $current->toArray();
        if ($definition['uuid'] !== $active['uuid']) { throw new \DomainException('Index policies belong to different forms.'); }
        $fields = array_column($active['fields'], null, 'uuid');
        foreach ($definition['fields'] as &$field) {
            $new = $fields[$field['uuid']] ?? null;
            if ($new !== null && $new['type'] === $field['type']) {
                $field['index'] = (bool) ($new['index'] ?? false) && ($new['persist'] ?? true);
            }
            // Historical non-persistence and sensitive-index consent remain authoritative.
            if (!($field['persist'] ?? true) || $field['type'] === 'password' || (($field['sensitive'] ?? false) && !($field['allow_sensitive_index'] ?? false))) { $field['index'] = false; }
        }
        unset($field);
        return new FormSpec($definition);
    }

    public static function signature(FormSpec $spec): array
    {
        $result = [];
        foreach ($spec->toArray()['fields'] as $field) {
            if (($field['index'] ?? false) && ($field['persist'] ?? true)) { $result[$field['uuid']] = $field['type']; }
        }
        ksort($result);
        return $result;
    }
}
