<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Domain;

/** Remap identities and declared references without rewriting literal answer values. */
final readonly class DefinitionRemapper
{
    /** @param array<string, \Nicode\FormStudio\Registry\ProviderRegistry> $registries */
    public function __construct(private array $registries = []) {}

    public function duplicateBranch(array $definition, string $root): array
    {
        $elements = array_column($definition['elements'], null, 'uuid');
        if (!Uuid::valid($root) || !isset($elements[$root])) { throw new \InvalidArgumentException('Unknown branch.'); }
        $selected = [$root => true];
        do {
            $before = count($selected);
            foreach ($elements as $uuid => $element) { if (isset($selected[$element['parent_uuid'] ?? ''])) { $selected[$uuid] = true; } }
        } while (count($selected) !== $before);
        $fragment = $definition;
        $fragment['elements'] = array_values(array_filter($definition['elements'], static fn (array $item): bool => isset($selected[$item['uuid']])));
        $fragment['fields'] = array_values(array_filter($definition['fields'], static fn (array $item): bool => isset($selected[$item['uuid']])));
        $fragment['actions'] = []; $fragment['validators'] = []; $fragment['post_submit'] = []; $fragment['rules'] = [];
        foreach ($definition['rules'] as $rule) {
            $rule['effects'] = array_values(array_filter($rule['effects'], static fn (array $effect): bool => isset($selected[$effect['target'] ?? ''])));
            if ($rule['effects'] !== []) { $fragment['rules'][] = $rule; }
        }
        $copy = $this->duplicate($fragment, $definition['uuid']); $mapped = $copy['definition']; $map = $copy['identities'];
        $names = array_fill_keys(array_column($definition['fields'], 'name'), true);
        foreach ($mapped['fields'] as &$field) {
            $base = substr($field['name'], 0, 235); $suffix = 1;
            do { $name = $base . '_copy' . ($suffix === 1 ? '' : '_' . $suffix); $suffix++; } while (isset($names[$name]));
            $field['name'] = $name; $names[$name] = true;
        } unset($field);
        foreach (['elements', 'fields', 'rules'] as $collection) { array_push($definition[$collection], ...$mapped[$collection]); }
        foreach ($mapped['translations'] ?? [] as $locale => $translation) {
            foreach (['fields', 'elements', 'options', 'validation'] as $collection) {
                foreach ($translation[$collection] ?? [] as $uuid => $text) {
                    if (in_array($uuid, $map, true) && !array_key_exists($uuid, $map)) { $definition['translations'][$locale][$collection][$uuid] = $text; }
                }
            }
        }
        return ['definition' => $definition, 'root' => $map[$root], 'identities' => $map];
    }

    public function duplicate(array $definition, string $formUuid): array
    {
        if (!Uuid::valid($formUuid) || !Uuid::valid($definition['uuid'] ?? null)) { throw new \InvalidArgumentException('Invalid form identity.'); }
        $map = [$definition['uuid'] => $formUuid];
        $identity = static function (array &$item) use (&$map): void {
            $old = $item['uuid'] ?? null;
            if (!Uuid::valid($old)) { throw new \InvalidArgumentException('Invalid definition identity.'); }
            $item['uuid'] = $map[$old] ??= Uuid::create();
        };
        foreach (['elements', 'fields', 'rules', 'actions'] as $collection) {
            foreach ($definition[$collection] as &$item) { $identity($item); }
            unset($item);
        }
        foreach ($definition['post_submit']['conditional_messages'] ?? [] as $index => $message) { if (isset($message['uuid'])) { $identity($message); $definition['post_submit']['conditional_messages'][$index] = $message; } }
        // Allocate every local identity before resolving references. A provider
        // may reference an option declared later in the authoring arrays.
        foreach ($definition['fields'] as &$field) {
            foreach ($field['options'] ?? [] as $i => $option) { if (isset($option['uuid'])) { $identity($option); $field['options'][$i] = $option; } }
        } unset($field);
        foreach ($definition['rules'] as &$rule) {
            foreach ($rule['effects'] as &$effect) {
                if ($effect['type'] === 'change_options') { foreach ($effect['value'] ?? [] as $index => $option) { if (isset($option['uuid'])) { $identity($option); $effect['value'][$index] = $option; } } }
            } unset($effect);
        } unset($rule);
        $reference = static fn ($uuid) => is_string($uuid) ? ($map[$uuid] ?? $uuid) : $uuid;
        foreach ($definition['elements'] as &$element) { if (isset($element['parent_uuid'])) { $element['parent_uuid'] = $reference($element['parent_uuid']); } }
        unset($element);
        foreach ($definition['fields'] as &$field) {
            $field['config'] = $this->configuration('fields', $field['type'], $field['config'] ?? [], $map);
            if (($field['prefill']['type'] ?? null) === 'field') { $field['prefill']['field'] = $reference($field['prefill']['field']); }
            if (isset($field['source'])) {
                $field['source'] = $this->source($field['source'], $map);
            }
            if (isset($field['validators'])) { $field['validators'] = $this->validators($field['validators'], $map); }
        }
        unset($field);
        if (isset($definition['validators'])) { $definition['validators'] = $this->validators($definition['validators'], $map); }
        foreach ($definition['rules'] as &$rule) {
            $rule['when'] = $this->condition($rule['when'], $map);
            foreach ($rule['effects'] as &$effect) {
                $effect = $this->configuration('effects', $effect['type'], $effect, $map); $effect['target'] = $reference($effect['target']);
            }
            unset($effect);
        }
        unset($rule);
        foreach ($definition['actions'] as &$action) {
            if (isset($action['condition'])) { $action['condition'] = $this->condition($action['condition'], $map); }
            $config = $this->configuration('actions', $action['type'], $action['config'] ?? [], $map);
            if (in_array($action['type'], ['email_notification', 'email_autoresponse'], true)) {
                foreach (['email_field', 'reply_to_field'] as $key) { if (isset($config[$key])) { $config[$key] = $reference($config[$key]); } }
                if (is_array($config['attachment_fields'] ?? null)) { $config['attachment_fields'] = array_map($reference, $config['attachment_fields']); }
            }
            if (in_array($action['type'], ['email_notification', 'email_autoresponse', 'webhook'], true)) {
                foreach (['subject', 'body_text', 'body_html'] as $key) { if (isset($config[$key])) { $config[$key] = $this->template($config[$key], $map); } }
                foreach (['payload', 'headers'] as $key) { foreach ($config[$key] ?? [] as $name => $template) { $config[$key][$name] = $this->template($template, $map); } }
            }
            $action['config'] = $config;
        }
        unset($action);
        if (isset($definition['post_submit']['preserve'])) { $definition['post_submit']['preserve'] = array_map($reference, $definition['post_submit']['preserve']); }
        if (isset($definition['post_submit']['summary_fields'])) { $definition['post_submit']['summary_fields'] = array_map($reference, $definition['post_submit']['summary_fields']); }
        foreach ($definition['post_submit']['conditional_messages'] ?? [] as $i => $message) { $definition['post_submit']['conditional_messages'][$i]['condition'] = $this->condition($message['condition'], $map); }
        foreach ($definition['translations'] ?? [] as $locale => $translation) {
            foreach (['fields', 'elements', 'actions', 'options', 'validation', 'conditional_messages'] as $collection) {
                if (!isset($translation[$collection])) { continue; }
                $translated = []; foreach ($translation[$collection] as $uuid => $value) {
                    if ($collection === 'actions') { foreach (['subject', 'body_text', 'body_html'] as $key) { if (isset($value[$key])) { $value[$key] = $this->template($value[$key], $map); } } }
                    $translated[$map[$uuid] ?? $uuid] = $value;
                }
                $definition['translations'][$locale][$collection] = $translated;
            }
        }
        $definition['uuid'] = $formUuid;
        return ['definition' => $definition, 'identities' => $map];
    }
    public function source(array $source, array $map): array
    {
        $reference = static fn ($uuid) => is_string($uuid) ? ($map[$uuid] ?? $uuid) : $uuid;
        $source['dependencies'] = array_map($reference, $source['dependencies'] ?? []);
        $source['config'] = $this->configuration('sources', $source['type'], $source['config'] ?? [], $map);
        if (in_array($source['type'], ['static', 'option_set'], true)) {
            foreach ($source['config']['options'] ?? [] as $index => $option) {
                if (isset($option['when'])) { $when = []; foreach ($option['when'] as $uuid => $value) { $when[$reference($uuid)] = $value; } $option['when'] = $when; }
                $source['config']['options'][$index] = $option;
            }
        }
        return $source;
    }
    private function condition(array $condition, array $map): array
    {
        if (isset($condition['group'])) { foreach ($condition['children'] as &$child) { $child = $this->condition($child, $map); } unset($child); }
        elseif (isset($condition['field'])) { $condition = $this->configuration('operators', $condition['operator'], $condition, $map); $condition['field'] = $map[$condition['field']] ?? $condition['field']; }
        return $condition;
    }
    private function validators(array $validators, array $map): array
    {
        foreach ($validators as &$validator) {
            $validator['config'] = $this->configuration('validators', $validator['type'], $validator['config'] ?? [], $map);
            foreach ($validator['config']['fields'] ?? [] as $i => $uuid) { $validator['config']['fields'][$i] = $map[$uuid] ?? $uuid; }
        }
        unset($validator); return $validators;
    }
    private function configuration(string $kind, string $type, array $config, array $map): array
    {
        $registry = $this->registries[$kind] ?? null;
        $metadata = $registry?->has($type) ? $registry->get($type)->metadata() : [];
        $paths = $metadata['reference_paths'] ?? [];
        if ($kind === 'sources') {
            foreach ($metadata['dependency_parameters'] ?? [] as $parameter) { $paths[] = '/' . str_replace(['~', '/'], ['~0', '~1'], $parameter); }
            if (in_array($type, ['joomla.categories', 'joomla.articles'], true)) { $paths[] = '/category_field'; }
        }
        foreach (array_unique($paths) as $path) {
            if (!is_string($path) || !str_starts_with($path, '/')) { throw new \DomainException('Invalid provider reference path.'); }
            $parts = array_map(static fn (string $part): string => str_replace(['~1', '~0'], ['/', '~'], $part), explode('/', substr($path, 1)));
            $this->pointer($config, $parts, $map);
        }
        foreach ($metadata['template_paths'] ?? [] as $path) {
            if (!is_string($path) || !str_starts_with($path, '/')) { throw new \DomainException('Invalid provider template path.'); }
            $parts = array_map(static fn (string $part): string => str_replace(['~1', '~0'], ['/', '~'], $part), explode('/', substr($path, 1)));
            $this->pointer($config, $parts, $map, true);
        }
        return $config;
    }
    private function pointer(mixed &$value, array $parts, array $map, bool $template = false): void
    {
        if ($parts === []) { if (is_string($value)) { $value = $template ? $this->template($value, $map) : ($map[$value] ?? $value); } return; }
        if (!is_array($value)) { return; }
        $part = array_shift($parts);
        if ($part === '*') { foreach ($value as &$child) { $this->pointer($child, $parts, $map, $template); } unset($child); }
        elseif (array_key_exists($part, $value)) { $this->pointer($value[$part], $parts, $map, $template); }
    }
    private function template(string $template, array $map): string
    {
        return preg_replace_callback('/(\{\{\s*field\.)([0-9a-f-]{36})(\.(?:value|label|option_label)\s*\}\})/i', static fn (array $match): string => $match[1] . ($map[$match[2]] ?? $match[2]) . $match[3], $template);
    }
}
