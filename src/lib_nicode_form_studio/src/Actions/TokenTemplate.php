<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Actions;

use Nicode\FormStudio\Rendering\Html;

final class TokenTemplate
{
    public function tokens(string $template): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $template, $matches);
        return array_values(array_unique($matches[1]));
    }
    public function render(string $template, array $values, string $context = 'text', ?int $maximumBytes = null): string
    {
        if (!in_array($context, ['text', 'html', 'header'], true)) { throw new \InvalidArgumentException('Unknown token output context.'); }
        if ($maximumBytes !== null && $maximumBytes < 0) { throw new \InvalidArgumentException('Invalid template byte limit.'); }
        $result = ''; $offset = 0;
        $append = static function (string $part) use (&$result, $maximumBytes): void {
            if ($maximumBytes !== null && strlen($part) > $maximumBytes - strlen($result)) { throw new \LengthException('Expanded template exceeds its byte limit.'); }
            $result .= $part;
        };
        while (preg_match('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $template, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $append(substr($template, $offset, $match[0][1] - $offset));
            $name = $match[1][0];
            if (!array_key_exists($name, $values) || !is_scalar($values[$name])) { throw new \DomainException('Template token unavailable.'); }
            $append($context === 'html' ? Html::escape($values[$name]) : (string) $values[$name]);
            $offset = $match[0][1] + strlen($match[0][0]);
        }
        $append(substr($template, $offset));
        if ($context === 'header' && preg_match('/[\x00-\x1f\x7f]/', $result)) { throw new \InvalidArgumentException('Mail header contains prohibited characters.'); }
        return $result;
    }
}
