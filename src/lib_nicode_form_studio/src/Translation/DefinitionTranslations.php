<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Translation;

use Nicode\FormStudio\Domain\{Diagnostic, FormSpec};

/** Presentation-only projection; its hash is not a published version identity. */
final class DefinitionTranslations
{
    private const PROPERTIES = ['form' => ['name', 'description'], 'fields' => ['label', 'help', 'description', 'placeholder'], 'elements' => ['title', 'text', 'description'], 'actions' => ['subject', 'body_text', 'body_html'], 'options' => ['label'], 'conditional_messages' => ['message']];
    private const EMAILS = ['email_notification', 'email_autoresponse'];
    public const MESSAGES = ['success', 'success_heading', 'validation_error', 'session_error', 'anti_spam_rejected', 'rate_limited', 'upload_error', 'captcha_error', 'captcha_unavailable', 'captcha_required', 'persistence_error', 'unexpected_error', 'processing_pending', 'action_blocking_failure', 'action_partial_failure'];
    public static function validate(array $definition): array
    {
        $errors = [];
        $error = static function (string $path) use (&$errors): void { $errors[] = new Diagnostic('translation.invalid', $path, 'Translations must reference existing content and contain only supported presentation strings.'); };
        if (isset($definition['base_language']) && !self::language($definition['base_language'])) { $error('/base_language'); }
        if (isset($definition['metadata']) && !is_array($definition['metadata'])) { $error('/metadata'); }
        elseif (isset($definition['metadata']['description']) && !is_string($definition['metadata']['description'])) { $error('/metadata/description'); }
        $translations = $definition['translations'] ?? [];
        if (!is_array($translations) || count($translations) > 100) { $error('/translations'); return $errors; }
        $identities = [];
        foreach (['fields', 'elements', 'actions'] as $kind) { $identities[$kind] = array_column($definition[$kind] ?? [], null, 'uuid'); }
        $identities['options'] = [];
        $identities['conditional_messages'] = [];
        if (is_array($definition['post_submit']['conditional_messages'] ?? null)) {
            foreach ($definition['post_submit']['conditional_messages'] as $message) { if (is_array($message) && isset($message['uuid']) && is_string($message['uuid'])) { $identities['conditional_messages'][$message['uuid']] = $message; } }
        }
        foreach ($definition['fields'] ?? [] as $field) {
            $sourceOptions = $field['source']['config']['options'] ?? [];
            foreach ([...($field['options'] ?? []), ...(is_array($sourceOptions) ? $sourceOptions : [])] as $option) {
                if (!is_array($option) || !isset($option['uuid'])) { continue; }
                if (!\Nicode\FormStudio\Domain\Uuid::valid($option['uuid'])) { $error('/fields/' . $field['uuid'] . '/options'); continue; }
                $identities['options'][$option['uuid']] = $option;
            }
            if (isset($field['config']['validation_messages']) && !self::messageMap($field['config']['validation_messages'])) { $error('/fields/' . $field['uuid'] . '/config/validation_messages'); }
        }
        foreach ($definition['rules'] ?? [] as $rule) {
            foreach ($rule['effects'] ?? [] as $effect) {
                if (($effect['type'] ?? '') !== 'change_options' || !is_array($effect['value'] ?? null)) { continue; }
                foreach ($effect['value'] as $option) {
                    if (!is_array($option) || !isset($option['uuid'])) { continue; }
                    if (!\Nicode\FormStudio\Domain\Uuid::valid($option['uuid'])) { $error('/rules/' . ($rule['uuid'] ?? '') . '/effects'); continue; }
                    $identities['options'][$option['uuid']] = $option;
                }
            }
        }
        $seenLanguages = [];
        foreach ($translations as $language => $translation) {
            $path = '/translations/' . $language;
            if (!self::language($language) || !is_array($translation)) { $error($path); continue; }
            if (isset($seenLanguages[strtolower($language)])) { $error($path); }
            $seenLanguages[strtolower($language)] = true;
            foreach ($translation as $kind => $items) {
                if ($kind === 'messages') {
                    if (!self::messageMap($items) || array_diff(array_keys($items), self::MESSAGES) !== []) { $error($path . '/messages'); }
                    continue;
                }
                if ($kind === 'validation') {
                    if (!is_array($items)) { $error($path . '/validation'); continue; }
                    foreach ($items as $uuid => $messages) { if (!isset($identities['fields'][$uuid]) || !self::messageMap($messages)) { $error($path . '/validation/' . $uuid); } }
                    continue;
                }
                if (!isset(self::PROPERTIES[$kind]) || !is_array($items)) { $error($path . '/' . $kind); continue; }
                if ($kind === 'form') { $items = ['form' => $items]; }
                foreach ($items as $uuid => $properties) {
                    if (($kind !== 'form' && !isset($identities[$kind][$uuid])) || !is_array($properties)) { $error($path . '/' . $kind . '/' . $uuid); continue; }
                    if ($kind === 'actions' && !in_array($identities[$kind][$uuid]['type'] ?? '', self::EMAILS, true)) { $error($path . '/' . $kind . '/' . $uuid); continue; }
                    foreach ($properties as $key => $value) {
                        if (!in_array($key, self::PROPERTIES[$kind], true) || !is_string($value) || strlen($value) > 65536 || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) { $error($path . '/' . $kind . '/' . $uuid . '/' . $key); }
                    }
                }
            }
        }
        return $errors;
    }
    private static function language(mixed $value): bool { return is_string($value) && preg_match('/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8}){0,3}$/D', $value) === 1; }
    private static function messageMap(mixed $messages): bool
    {
        if (!is_array($messages) || count($messages) > 100) { return false; }
        foreach ($messages as $key => $value) { if (!is_string($key) || preg_match('/^[a-z][a-z0-9_.]{0,63}$/D', $key) !== 1 || !is_string($value) || strlen($value) > 65536 || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) { return false; } }
        return true;
    }
    public static function spec(FormSpec $spec, string $locale): FormSpec { return new FormSpec(self::resolve($spec->toArray(), $locale)); }
    public static function messages(array $definition, array $fallback = []): array
    {
        foreach (self::MESSAGES as $category) {
            if (!isset($definition['post_submit']['messages'][$category])) { continue; }
            try { $fallback[$category] = (new \Nicode\FormStudio\Actions\TokenTemplate())->render($definition['post_submit']['messages'][$category], ['form.name' => $definition['name'], 'submission.reference' => '', 'submission.date' => '']); }
            catch (\Throwable) { /* A malformed historic message cannot replace the safe language fallback. */ }
        }
        return $fallback;
    }
    public static function resolve(array $definition, string $locale): array
    {
        $catalogue = [];
        foreach ($definition['translations'] ?? [] as $language => $translation) { $catalogue[strtolower($language)] = $translation; }
        $locale = strtolower($locale);
        $languages = array_unique([strtolower($definition['base_language'] ?? 'en-GB'), explode('-', $locale)[0], $locale]);
        foreach ($languages as $language) {
            $translation = $catalogue[$language] ?? [];
            if (isset($translation['form']['name'])) { $definition['name'] = $translation['form']['name']; }
            if (isset($translation['form']['description'])) { $definition['metadata']['description'] = $translation['form']['description']; }
            if (isset($translation['messages'])) { $definition['post_submit']['messages'] = array_replace($definition['post_submit']['messages'] ?? [], array_intersect_key($translation['messages'], array_flip(self::MESSAGES))); }
            foreach ($definition['post_submit']['conditional_messages'] ?? [] as $index => $message) {
                if (isset($message['uuid'], $translation['conditional_messages'][$message['uuid']]['message'])) { $definition['post_submit']['conditional_messages'][$index]['message'] = $translation['conditional_messages'][$message['uuid']]['message']; }
            }
            foreach (['fields', 'elements', 'actions'] as $kind) {
                foreach ($definition[$kind] ?? [] as $index => $item) {
                    $text = array_intersect_key($translation[$kind][$item['uuid']] ?? [], array_flip(self::PROPERTIES[$kind]));
                    if ($kind === 'actions' && !in_array($item['type'], self::EMAILS, true)) { continue; }
                    if ($kind === 'elements') { $definition[$kind][$index] = array_replace($item, $text); }
                    elseif ($text !== []) { $definition[$kind][$index]['config'] = array_replace($item['config'] ?? [], $text); }
                }
            }
            foreach ($definition['fields'] ?? [] as $index => $field) {
                if (isset($translation['validation'][$field['uuid']])) { $definition['fields'][$index]['config']['validation_messages'] = array_replace($field['config']['validation_messages'] ?? [], $translation['validation'][$field['uuid']]); }
                if (isset($field['options'])) { $definition['fields'][$index]['options'] = self::options($field['options'], $translation['options'] ?? []); }
                if (in_array($field['source']['type'] ?? '', ['static', 'option_set', 'relational'], true) && isset($field['source']['config']['options'])) { $definition['fields'][$index]['source']['config']['options'] = self::options($field['source']['config']['options'], $translation['options'] ?? []); }
            }
            foreach ($definition['rules'] ?? [] as $ruleIndex => $rule) {
                foreach ($rule['effects'] ?? [] as $effectIndex => $effect) {
                    if (($effect['type'] ?? '') === 'change_options' && is_array($effect['value'] ?? null)) { $definition['rules'][$ruleIndex]['effects'][$effectIndex]['value'] = self::options($effect['value'], $translation['options'] ?? []); }
                }
            }
        }
        return $definition;
    }
    private static function options(array $options, array $translations): array
    {
        foreach ($options as &$option) { if (isset($option['uuid'], $translations[$option['uuid']]['label'])) { $option['label'] = $translations[$option['uuid']]['label']; } }
        unset($option);
        return $options;
    }
}
