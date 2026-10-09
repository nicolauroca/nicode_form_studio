<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Actions;

use Nicode\FormStudio\Domain\Uuid;

/** Reusable named field tokens are bound explicitly when copying into a draft. */
final class ReusableEmailTemplate
{
    private const GLOBALS = ['form.name', 'form.uuid', 'submission.reference', 'submission.date', 'response.summary'];
    public function validate(array $content): array
    {
        if (array_diff(array_keys($content), ['subject', 'body_text', 'body_html']) !== []) { throw new \InvalidArgumentException('Unknown email template properties.'); }
        $parameters = [];
        foreach (['subject', 'body_text', 'body_html'] as $property) {
            $text = $content[$property] ?? ($property === 'body_html' ? '' : null);
            if (!is_string($text) || strlen($text) > 262144 || !mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) { throw new \InvalidArgumentException('Invalid email template text.'); }
            if ($property === 'subject' && preg_match('/[\x00-\x1f\x7f]/', $text)) { throw new \InvalidArgumentException('Unsafe email template subject.'); }
            foreach ((new TokenTemplate())->tokens($text) as $token) {
                if (in_array($token, self::GLOBALS, true)) { continue; }
                if (preg_match('/^input\.([a-z][a-z0-9_]{0,63})\.(value|label|option_label)$/D', $token, $matches) !== 1) { throw new \InvalidArgumentException('Unknown reusable template token.'); }
                $parameters[$matches[1]] = true;
            }
        }
        $names = array_keys($parameters); sort($names, SORT_STRING); return $names;
    }
    public function bind(array $content, array $definition, array $bindings): array
    {
        $parameters = $this->validate($content); $keys = array_keys($bindings); sort($keys, SORT_STRING);
        if ($keys !== $parameters) { throw new \InvalidArgumentException('Bind every template parameter exactly once.'); }
        $fields = array_column($definition['fields'] ?? [], null, 'uuid');
        foreach ($bindings as $uuid) {
            if (!Uuid::valid($uuid) || !isset($fields[$uuid]) || $fields[$uuid]['type'] === 'password' || !($fields[$uuid]['include_email'] ?? !($fields[$uuid]['sensitive'] ?? false))) { throw new \InvalidArgumentException('Template binding references an unavailable or excluded field.'); }
        }
        $result = [];
        foreach (['subject', 'body_text', 'body_html'] as $property) {
            if ($property === 'body_html' && ($content[$property] ?? '') === '') { continue; }
            $result[$property] = preg_replace_callback('/\{\{\s*input\.([a-z][a-z0-9_]{0,63})\.(value|label|option_label)\s*\}\}/', static fn (array $match): string => '{{field.' . $bindings[$match[1]] . '.' . $match[2] . '}}', $content[$property]);
        }
        return $result;
    }
}
