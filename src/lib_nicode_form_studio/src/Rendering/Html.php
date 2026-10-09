<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Rendering;

final class Html
{
    public static function escape(mixed $value): string { return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public static function attributes(array $attributes): string
    {
        $result = '';
        foreach ($attributes as $key => $value) {
            if (preg_match('/^[a-z][a-z0-9_-]*$/D', $key) !== 1) { throw new \InvalidArgumentException('Invalid HTML attribute.'); }
            if ($value === null || $value === false) { continue; }
            $result .= ' ' . $key . ($value === true ? '' : '="' . self::escape($value) . '"');
        }
        return $result;
    }
}
