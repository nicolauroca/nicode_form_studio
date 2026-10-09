<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Validation;

/** Calendar validation and sortable local values, independent of server timezone. */
final class Temporal
{
    public const TYPES = ['date', 'time', 'datetime', 'month', 'week'];
    public static function key(string $type, mixed $value): ?string
    {
        if (!is_string($value)) { return null; }
        if ($type === 'time') {
            if (preg_match('/^([0-9]{2}):([0-9]{2})(?::([0-9]{2}))?$/D', $value, $parts) !== 1 || (int) $parts[1] > 23 || (int) $parts[2] > 59 || (int) ($parts[3] ?? 0) > 59) { return null; }
            return strlen($value) === 5 ? $value . ':00' : $value;
        }
        if ($type === 'datetime') {
            $parts = explode('T', $value);
            if (count($parts) !== 2) { return null; }
            $date = self::key('date', $parts[0]); $time = self::key('time', $parts[1]);
            return $date !== null && $time !== null ? $date . 'T' . $time : null;
        }
        if ($type === 'date') {
            if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts) !== 1 || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) { return null; }
            return $value;
        }
        if ($type === 'month') { return preg_match('/^(?!0000)[0-9]{4}-(?:0[1-9]|1[0-2])$/D', $value) === 1 ? $value : null; }
        if ($type === 'week') {
            if (preg_match('/^([0-9]{4})-W([0-9]{2})$/D', $value, $parts) !== 1 || (int) $parts[1] < 1) { return null; }
            $year = (int) $parts[1]; $previous = $year - 1;
            $jan1 = ($previous + intdiv($previous, 4) - intdiv($previous, 100) + intdiv($previous, 400) + 1) % 7;
            $leap = $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0);
            $weeks = $jan1 === 4 || ($jan1 === 3 && $leap) ? 53 : 52;
            return (int) $parts[2] >= 1 && (int) $parts[2] <= $weeks ? $value : null;
        }
        return null;
    }
}
