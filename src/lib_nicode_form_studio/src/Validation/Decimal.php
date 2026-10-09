<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Validation;

/** Exact decimal arithmetic without float conversion or a platform integer limit. */
final class Decimal
{
    public static function normalize(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new \InvalidArgumentException('Expected an exact decimal string.');
        }
        $value = trim((string) $value);
        if (preg_match('/^([+-]?)([0-9]+)(?:\.([0-9]+))?$/D', $value, $match) !== 1) {
            throw new \InvalidArgumentException('Invalid decimal.');
        }
        $integer = ltrim($match[2], '0') ?: '0';
        $fraction = rtrim($match[3] ?? '', '0');
        $negative = $match[1] === '-' && ($integer !== '0' || $fraction !== '');
        return ($negative ? '-' : '') . $integer . ($fraction !== '' ? '.' . $fraction : '');
    }

    public static function compare(string $a, string $b): int
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        $negativeA = str_starts_with($a, '-');
        $negativeB = str_starts_with($b, '-');
        if ($negativeA !== $negativeB) {
            return $negativeA ? -1 : 1;
        }
        [$ai, $af] = array_pad(explode('.', ltrim($a, '-')), 2, '');
        [$bi, $bf] = array_pad(explode('.', ltrim($b, '-')), 2, '');
        $comparison = strlen($ai) <=> strlen($bi);
        $comparison = $comparison ?: strcmp($ai, $bi);
        $length = max(strlen($af), strlen($bf));
        $comparison = $comparison ?: strcmp(str_pad($af, $length, '0'), str_pad($bf, $length, '0'));
        return ($comparison <=> 0) * ($negativeA ? -1 : 1);
    }

    public static function stepMatches(string $value, string $step, string $base = '0'): bool
    {
        if (self::compare($step, '0') <= 0) {
            throw new \InvalidArgumentException('Step must be positive.');
        }
        if (!extension_loaded('bcmath')) {
            throw new \RuntimeException('Exact step validation requires ext-bcmath.');
        }
        $scale = max(self::scale($value), self::scale($step), self::scale($base));
        return self::compare(bcmod(bcsub($value, $base, $scale), $step, $scale), '0') === 0;
    }

    public static function scale(string $value): int
    {
        $parts = explode('.', self::normalize($value));
        return strlen($parts[1] ?? '');
    }
}
