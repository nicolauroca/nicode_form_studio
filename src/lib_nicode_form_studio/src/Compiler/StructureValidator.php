<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Compiler;

use Nicode\FormStudio\Domain\Diagnostic;

/** Reject malformed JSON shapes before semantic visitors access nested values. */
final class StructureValidator
{
    public function validate(array $draft): array
    {
        $errors = [];
        foreach (['elements', 'fields', 'rules', 'actions'] as $collection) {
            foreach ($draft[$collection] ?? [] as $i => $item) {
                $path = "/$collection/$i";
                if (!is_array($item)) { $errors[] = new Diagnostic('schema.object', $path, 'Expected an object.'); continue; }
                if ($collection === 'elements' && ($item['parent_uuid'] ?? null) !== null && !\Nicode\FormStudio\Domain\Uuid::valid($item['parent_uuid'])) {
                    $errors[] = new Diagnostic('element.parent', "$path/parent_uuid", 'Parent must be null or a valid UUID.');
                }
                $this->types($item, ['uuid' => 'string', 'type' => 'string', 'name' => 'string', 'title' => 'string', 'description' => 'string', 'text' => 'string', 'enabled' => 'boolean', 'order' => 'integer', 'priority' => 'integer', 'visible' => 'boolean', 'sensitive' => 'boolean', 'index' => 'boolean', 'allow_sensitive_index' => 'boolean', 'persist' => 'boolean'], $path, $errors);
                foreach (['config', 'width', 'source', 'prefill'] as $key) {
                    if (isset($item[$key]) && !is_array($item[$key])) { $errors[] = new Diagnostic('schema.object', "$path/$key", 'Expected an object.'); }
                }
                foreach (['options', 'validators', 'effects'] as $key) {
                    if (!isset($item[$key])) { continue; }
                    if (!is_array($item[$key]) || !array_is_list($item[$key])) { $errors[] = new Diagnostic('schema.list', "$path/$key", 'Expected an ordered list.'); continue; }
                    foreach ($item[$key] as $j => $child) {
                        if (!is_array($child)) { $errors[] = new Diagnostic('schema.object', "$path/$key/$j", 'Expected an object.'); }
                    }
                }
                if ($collection === 'fields' && is_array($item['config'] ?? null)) {
                    $this->types($item['config'], ['label' => 'string', 'help' => 'string', 'description' => 'string', 'placeholder' => 'string', 'autocomplete' => 'string', 'inputmode' => 'string', 'required' => 'boolean', 'readonly' => 'boolean', 'disabled' => 'boolean', 'visible' => 'boolean', 'trim' => 'boolean'], "$path/config", $errors);
                    $this->types($item['config'], ['admin_label' => 'string', 'css_class' => 'string'], "$path/config", $errors);
                    if (isset($item['config']['css_class']) && !\Nicode\FormStudio\Field\CommonConfiguration::validClasses($item['config']['css_class'])) { $errors[] = new Diagnostic('field.css_class', "$path/config/css_class", 'Use up to eight nfs-custom- class tokens.'); }
                    if (isset($item['config']['inputmode']) && !in_array($item['config']['inputmode'], ['', 'none', 'text', 'decimal', 'numeric', 'tel', 'search', 'email', 'url'], true)) { $errors[] = new Diagnostic('field.inputmode', "$path/config/inputmode", 'Unsupported input mode.'); }
                    if (isset($item['config']['autocomplete']) && (!is_string($item['config']['autocomplete']) || preg_match('/^[a-zA-Z0-9 _-]{0,255}$/D', $item['config']['autocomplete']) !== 1)) { $errors[] = new Diagnostic('field.autocomplete', "$path/config/autocomplete", 'Invalid autocomplete tokens.'); }
                    foreach (['min', 'max', 'step'] as $bound) {
                        if (isset($item['config'][$bound]) && !is_string($item['config'][$bound]) && !is_int($item['config'][$bound])) { $errors[] = new Diagnostic('schema.type', "$path/config/$bound", 'Expected an exact string or integer.'); }
                    }
                }
                $this->types($item, ['include_email' => 'boolean', 'include_export' => 'boolean', 'sortable' => 'boolean'], $path, $errors);
                if ($collection === 'fields' && is_string($item['name'] ?? null) && mb_strlen($item['name']) > 255) { $errors[] = new Diagnostic('field.name.length', "$path/name", 'Machine name exceeds storage length.'); }
                if (is_array($item['source'] ?? null) && isset($item['source']['dependencies'])) {
                    if (!is_array($item['source']['dependencies']) || !array_is_list($item['source']['dependencies'])) { $errors[] = new Diagnostic('schema.list', "$path/source/dependencies", 'Expected a dependency list.'); }
                }
                if (is_array($item['source'] ?? null) && array_key_exists('ttl', $item['source'])) {
                    $ttl = $item['source']['ttl'];
                    if (!is_int($ttl) || $ttl < 0 || $ttl > 86400) { $errors[] = new Diagnostic('source.ttl', "$path/source/ttl", 'Source TTL must be an integer between 0 and 86400 seconds.'); }
                }
            }
        }
        return $errors;
    }

    private function types(array $object, array $types, string $path, array &$errors): void
    {
        foreach ($types as $key => $type) {
            if (array_key_exists($key, $object) && gettype($object[$key]) !== $type) {
                $errors[] = new Diagnostic('schema.type', "$path/$key", 'Expected ' . $type . '.');
            }
        }
    }
}
