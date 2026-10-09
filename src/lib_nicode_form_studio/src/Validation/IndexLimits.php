<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Validation;
final class IndexLimits
{
    public static function validate(?string $type, mixed $value, bool $multiple): array
    {
        if ($type === null) { return []; }
        $errors = [];
        foreach ($multiple ? $value : [$value] as $item) {
            if ($item === null || $item === '') { continue; }
            if ($type === 'keyword' && mb_strlen((string) $item) > 255) { $errors[] = 'index_length'; }
            if (in_array($type, ['date', 'datetime'], true) && (Temporal::key($type, $item) === null || strcmp(substr((string) $item, 0, 4), '1000') < 0)) { $errors[] = 'index_date'; }
            if ($type === 'decimal') {
                $decimal = Decimal::normalize($item);
                if (Decimal::scale($decimal) > 12 || strlen(explode('.', ltrim($decimal, '-'))[0]) > 26) { $errors[] = 'index_precision'; }
            }
        }
        return array_values(array_unique($errors));
    }
}
