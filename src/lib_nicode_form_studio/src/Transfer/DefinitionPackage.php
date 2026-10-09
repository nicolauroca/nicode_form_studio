<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Transfer;

use Nicode\FormStudio\Contract\PortableProviderInterface;
use Nicode\FormStudio\Domain\{CanonicalJson, Uuid};
use Nicode\FormStudio\Registry\ProviderRegistry;

/** Versioned data envelope, never an SQL dump or executable archive. */
final readonly class DefinitionPackage
{
    public const FORMAT = 'nicode.formstudio.definition';
    /** @param array<string, ProviderRegistry> $registries */
    public function __construct(private array $registries = []) {}

    public function export(array $definition, string $mode = 'portable'): array
    {
        if (!in_array($mode, ['portable', 'reference-aware'], true)) { throw new \InvalidArgumentException('Unknown definition export mode.'); }
        $this->shape($definition); $review = [];
        $sensitive = [];
        foreach ($definition['fields'] as $field) { if ($field['type'] === 'password' || ($field['sensitive'] ?? false)) { $sensitive[$field['uuid']] = true; } }
        $keys = ['schema_version', 'uuid', 'name', 'metadata', 'publication', 'elements', 'fields', 'rules', 'validators', 'actions', 'presentation', 'post_submit', 'privacy', 'security', 'persistence', 'translations', 'base_language', 'provider_dependencies', 'compatibility'];
        $copy = $this->scrub(array_intersect_key($definition, array_flip($keys)), '', $review);
        foreach ($copy['fields'] as $i => &$field) {
            $path = '/fields/' . $i;
            $field['config'] = $this->configuration('fields', $field['type'], $field['config'] ?? [], $mode, $path . '/config', $review);
            if (isset($sensitive[$field['uuid']]) && ($field['prefill']['type'] ?? null) === 'constant') { unset($field['prefill']); $review[] = ['path' => $path . '/prefill', 'reason' => 'sensitive_default']; }
            if (($field['type'] === 'password' || ($field['sensitive'] ?? false)) && array_key_exists('default', $field['config'])) {
                unset($field['config']['default']); $review[] = ['path' => $path . '/config/default', 'reason' => 'sensitive_default'];
            }
            if (isset($field['source'])) {
                $source = &$field['source'];
                $source['config'] = $this->configuration('sources', $source['type'], $source['config'] ?? [], $mode, $path . '/source/config', $review);
                if ($mode === 'portable' && $source['type'] === 'option_set') {
                    $source['type'] = 'static'; unset($source['config']['resource_uuid'], $source['config']['revision'], $source['config']['resource_hash']);
                }
                if (in_array($source['type'], ['joomla.categories', 'joomla.articles'], true)) { $review[] = ['path' => $path . '/source/config/category_ids', 'reason' => 'joomla_category_binding']; }
                unset($source);
            }
            foreach ($field['validators'] ?? [] as $j => $validator) { $field['validators'][$j]['config'] = $this->configuration('validators', $validator['type'], $validator['config'] ?? [], $mode, "$path/validators/$j/config", $review); }
        }
        unset($field);
        foreach ($copy['validators'] ?? [] as $i => $validator) { $copy['validators'][$i]['config'] = $this->configuration('validators', $validator['type'], $validator['config'] ?? [], $mode, "/validators/$i/config", $review); }
        foreach ($copy['actions'] as $i => &$action) {
            $path = '/actions/' . $i . '/config';
            $action['config'] = $this->configuration('actions', $action['type'], $action['config'] ?? [], $mode, $path, $review);
            if ($action['type'] === 'webhook') {
                if (($action['config']['headers'] ?? []) !== []) { unset($action['config']['headers']); $review[] = ['path' => $path . '/headers', 'reason' => 'private_headers']; }
                if (isset($action['config']['url'])) {
                    $url = $action['config']['url']; $parts = is_string($url) ? parse_url($url) : false;
                    if (!$parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) { unset($action['config']['url']); $review[] = ['path' => $path . '/url', 'reason' => 'private_url']; }
                }
            }
            if ($action['type'] === 'redirect' && isset($action['config']['menu_id'])) { $review[] = ['path' => $path . '/menu_id', 'reason' => 'joomla_menu_binding']; }
        }
        unset($action);
        // Dependencies describe the exported definition; a portable OptionSet becomes a static snapshot.
        if ($mode === 'portable' && isset($copy['provider_dependencies']['sources']['option_set'])) {
            unset($copy['provider_dependencies']['sources']['option_set']); $copy['provider_dependencies']['sources']['static'] = '1.0.0';
        }
        $copy = $this->sensitiveLiterals($this->scrub($copy, '', $review), '', $sensitive, $review);
        $review = array_values(array_unique($review, SORT_REGULAR));
        return ['format' => self::FORMAT, 'format_version' => '1.0', 'mode' => $mode, 'definition' => $copy, 'definition_hash' => hash('sha256', CanonicalJson::encode($copy)), 'review' => $review];
    }
    public function decode(string $json): array
    {
        if (strlen($json) > 2097152 || !mb_check_encoding($json, 'UTF-8')) { throw new \InvalidArgumentException('Definition package exceeds the supported input size or encoding.'); }
        $package = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($package) || ($package['format'] ?? null) !== self::FORMAT || ($package['format_version'] ?? null) !== '1.0' || !in_array($package['mode'] ?? null, ['portable', 'reference-aware'], true) || !is_array($package['definition'] ?? null) || !is_string($package['definition_hash'] ?? null)) { throw new \InvalidArgumentException('Unsupported definition package.'); }
        $this->shape($package['definition']);
        if (!hash_equals(hash('sha256', CanonicalJson::encode($package['definition'])), $package['definition_hash'])) { throw new \DomainException('Definition package integrity mismatch.'); }
        // Notes contain only paths and fixed reason codes, never messages to execute or trust.
        $notes = $package['review'] ?? [];
        if (!is_array($notes) || !array_is_list($notes) || count($notes) > 10000) { throw new \InvalidArgumentException('Invalid package review notes.'); }
        foreach ($notes as $note) {
            if (!is_array($note) || !is_string($note['path'] ?? null) || strlen($note['path']) > 2048 || !str_starts_with($note['path'], '/') || !in_array($note['reason'] ?? null, ['credential', 'sensitive_default', 'sensitive_literal', 'joomla_category_binding', 'provider_configuration', 'private_headers', 'private_url', 'joomla_menu_binding', 'unknown_configuration'], true) || array_diff(array_keys($note), ['path', 'reason']) !== []) { throw new \InvalidArgumentException('Invalid package review note.'); }
        }
        $safe = $this->export($package['definition'], $package['mode']);
        $safe['review'] = array_values(array_unique([...$notes, ...$safe['review']], SORT_REGULAR));
        return $safe;
    }
    private function shape(array $definition): void
    {
        if (($definition['schema_version'] ?? null) !== '1.0' || !Uuid::valid($definition['uuid'] ?? null) || !is_string($definition['name'] ?? null) || trim($definition['name']) === '' || mb_strlen($definition['name']) > 255) { throw new \InvalidArgumentException('Unsupported or invalid FormSpec identity/schema.'); }
        foreach (['elements', 'fields', 'rules', 'actions'] as $key) {
            if (!is_array($definition[$key] ?? null) || !array_is_list($definition[$key])) { throw new \InvalidArgumentException('Invalid definition collection.'); }
            $seen = [];
            foreach ($definition[$key] as $item) {
                if (!is_array($item) || !Uuid::valid($item['uuid'] ?? null) || isset($seen[$item['uuid']])) { throw new \InvalidArgumentException('Invalid or duplicate definition item identity.'); }
                $seen[$item['uuid']] = true;
            }
            foreach ($definition[$key] as $item) {
                if ($key !== 'rules' && (!is_string($item['type'] ?? null) || strlen($item['type']) > 64)) { throw new \InvalidArgumentException('Missing or invalid definition item type.'); }
                if ($key === 'fields' && !is_string($item['name'] ?? null)) { throw new \InvalidArgumentException('Missing field name.'); }
                if ($key === 'rules' && (!is_array($item['when'] ?? null) || !is_array($item['effects'] ?? null))) { throw new \InvalidArgumentException('Invalid rule structure.'); }
                if (isset($item['parent_uuid']) && !Uuid::valid($item['parent_uuid'])) { throw new \InvalidArgumentException('Invalid parent identity.'); }
                if (isset($item['priority']) && (!is_int($item['priority']) || $item['priority'] < -2147483648 || $item['priority'] > 2147483647)) { throw new \InvalidArgumentException('Invalid rule priority.'); }
                foreach (['logical_type', 'failure_policy'] as $property) { if (isset($item[$property]) && (!is_string($item[$property]) || strlen($item[$property]) > 64)) { throw new \InvalidArgumentException('Invalid definition policy.'); } }
            }
        }
        if ((new \Nicode\FormStudio\Compiler\StructureValidator())->validate($definition) !== []) { throw new \InvalidArgumentException('Invalid definition structure.'); }
        $providerShape = static function (mixed $item): void {
            if (!is_array($item) || !is_string($item['type'] ?? null) || (isset($item['config']) && !is_array($item['config']))) { throw new \InvalidArgumentException('Invalid provider configuration shape.'); }
        };
        $names = [];
        foreach ($definition['fields'] as $field) {
            $providerShape($field);
            if (mb_strlen($field['name']) > 255 || isset($names[$field['name']])) { throw new \InvalidArgumentException('Invalid or duplicate field name.'); }
            $names[$field['name']] = true; $values = [];
            foreach ($field['options'] ?? [] as $option) {
                if (!is_string($option['value'] ?? null) || mb_strlen($option['value']) > 255 || !is_string($option['label'] ?? null) || isset($values[$option['value']]) || (isset($option['uuid']) && !Uuid::valid($option['uuid']))) { throw new \InvalidArgumentException('Invalid or duplicate local option.'); }
                $values[$option['value']] = true;
            }
            if (isset($field['source'])) { $providerShape($field['source']); }
            foreach ($field['validators'] ?? [] as $validator) { $providerShape($validator); }
        }
        foreach ($definition['actions'] as $action) { $providerShape($action); }
        if (isset($definition['validators']) && (!is_array($definition['validators']) || !array_is_list($definition['validators']))) { throw new \InvalidArgumentException('Invalid validators collection.'); }
        foreach ($definition['validators'] ?? [] as $validator) { $providerShape($validator); }
        CanonicalJson::encode($definition);
    }
    private function configuration(string $kind, string $type, array $config, string $mode, string $path, array &$review): array
    {
        $registry = $this->registries[$kind] ?? null;
        $provider = $registry?->has($type) ? $registry->get($type) : null;
        if ($provider instanceof PortableProviderInterface) { return $this->scrub($provider->exportConfiguration($config, $mode), $path, $review); }
        $core = $provider instanceof \Nicode\FormStudio\Field\ScalarFieldType || $provider instanceof \Nicode\FormStudio\Field\FileFieldType
            || $provider instanceof \Nicode\FormStudio\Actions\EmailAction || $provider instanceof \Nicode\FormStudio\Actions\WebhookAction || $provider instanceof \Nicode\FormStudio\Actions\NavigationAction
            || $provider instanceof \Nicode\FormStudio\DataSource\StaticDataSource || $provider instanceof \Nicode\FormStudio\Infrastructure\Joomla\EntitySource || $provider instanceof \Nicode\FormStudio\Validation\RelationalValidator;
        if (!$core) { if ($config !== []) { $review[] = ['path' => $path, 'reason' => 'provider_configuration']; } return []; }
        $keys = array_keys($provider->metadata()['configuration_schema']['properties'] ?? []);
        if ($kind === 'fields') { $keys = [...$keys, 'label', 'admin_label', 'help', 'description', 'placeholder', 'css_class', 'validation_messages', 'required', 'readonly', 'disabled', 'visible', 'default', 'autocomplete', 'inputmode', 'trim', 'min', 'max']; }
        elseif ($kind === 'actions' && in_array($type, ['email_notification', 'email_autoresponse'], true)) { $keys = [...$keys, 'template', 'template_translations', 'attachment_fields']; }
        elseif ($provider instanceof \Nicode\FormStudio\DataSource\StaticDataSource) { $keys = ['resource_uuid', 'revision', 'resource_hash', 'options']; }
        elseif ($provider instanceof \Nicode\FormStudio\Infrastructure\Joomla\EntitySource) { $keys = ['category_ids', 'category_field', 'max_options']; }
        elseif ($provider instanceof \Nicode\FormStudio\Validation\RelationalValidator) { $keys = ['fields', 'count']; }
        foreach (array_diff(array_keys($config), $keys) as $key) { $review[] = ['path' => $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $key), 'reason' => 'unknown_configuration']; }
        return array_intersect_key($config, array_flip($keys));
    }
    private function sensitiveLiterals(mixed $value, string $path, array $sensitive, array &$review): mixed
    {
        if (!is_array($value)) { return $value; }
        $target = $value['field'] ?? $value['target'] ?? null;
        if (is_string($target) && isset($sensitive[$target]) && array_key_exists('value', $value)) {
            unset($value['value']); $review[] = ['path' => $path . '/value', 'reason' => 'sensitive_literal'];
        }
        // An option depending on a private literal must not become unconditional.
        if (is_array($value['when'] ?? null)) {
            foreach ($value['when'] as $uuid => $literal) {
                if (isset($sensitive[$uuid]) && $literal !== null) {
                    $value['when'][$uuid] = null; $value['enabled'] = false;
                    $review[] = ['path' => $path . '/when/' . $uuid, 'reason' => 'sensitive_literal'];
                }
            }
        }
        foreach ($value as $key => $child) { $value[$key] = $this->sensitiveLiterals($child, $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $key), $sensitive, $review); }
        return $value;
    }
    private function scrub(mixed $value, string $path, array &$review): mixed
    {
        if ($path === '/provider_dependencies') { return $value; }
        if (!is_array($value)) { return $value; }
        $result = [];
        foreach ($value as $key => $child) {
            $next = $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $key);
            $normal = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', preg_replace('/([A-Z])([A-Z][a-z])/', '$1_$2', (string) $key));
            if (is_string($key) && preg_match('/(?:^|[_-])(?:password|secret|secrets|token|credential|credentials|api[_-]?key|authorization|cookie|csrf)(?:$|[_-])/i', $normal)) { $review[] = ['path' => $next, 'reason' => 'credential']; continue; }
            $result[$key] = $this->scrub($child, $next, $review);
        }
        return $result;
    }
}
