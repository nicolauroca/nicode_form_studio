<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Validation;

/** Linear-safe common subset: ASCII atoms, fixed repeats and one variable repeat. */
final class SafePattern
{
    public static function valid(string $pattern): bool
    {
        if (strlen($pattern) > 512 || preg_match('/[^\x20-\x7e]/', $pattern) || str_contains($pattern, '~')) {
            return false;
        }
        $body = $pattern;
        if (str_starts_with($body, '^')) { $body = substr($body, 1); }
        if (str_ends_with($body, '$') && !str_ends_with($body, '\\$')) { $body = substr($body, 0, -1); }
        $offset = 0; $variable = 0;
        while ($offset < strlen($body)) {
            if (!preg_match('/\\G(?:\\\\[dws.\[\]{}+*?^$\\\\-]|\[(?:\\\\[\\\\\]\-]|[^\]\\\\])+\]|[^()|\[\]{}+*?^$\\\\])/', $body, $atom, 0, $offset)) { return false; }
            $offset += strlen($atom[0]);
            if (preg_match('/\\G([+*?]|\{([0-9]+)(?:,([0-9]*))?\})/', $body, $quantifier, 0, $offset)) {
                $offset += strlen($quantifier[0]);
                if (strlen($quantifier[0]) === 1) { $variable++; }
                else {
                    $min = (int) $quantifier[2];
                    $hasRange = str_contains($quantifier[0], ',');
                    $max = $hasRange && ($quantifier[3] ?? '') !== '' ? (int) $quantifier[3] : $min;
                    if ($min > 1000 || $max > 1000 || $max < $min) { return false; }
                    if ($hasRange && (($quantifier[3] ?? '') === '' || $min !== $max)) { $variable++; }
                }
                if ($variable > 1) { return false; }
            }
        }
        set_error_handler(static fn (): bool => true);
        try {
            return preg_match('~(*LIMIT_MATCH=100000)(*LIMIT_DEPTH=100)\\A(?:' . self::expand($pattern) . ')\\z~u', '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    public static function matches(string $pattern, string $value): bool
    {
        if (!self::valid($pattern)) {
            throw new \InvalidArgumentException('Unsupported pattern.');
        }
        return preg_match('~(*LIMIT_MATCH=100000)(*LIMIT_DEPTH=100)\\A(?:' . self::expand($pattern) . ')\\z~u', $value) === 1;
    }

    private static function expand(string $pattern): string
    {
        // PCRE and ECMAScript disagree on Unicode shorthand. Fix the alphabet.
        return preg_replace_callback('/\\\\./', static fn (array $match): string => match ($match[0]) {
            '\\d' => '[0-9]', '\\w' => '[A-Za-z0-9_]', '\\s' => '[ \\t\\r\\n\\f\\v]', default => $match[0],
        }, $pattern);
    }
}
