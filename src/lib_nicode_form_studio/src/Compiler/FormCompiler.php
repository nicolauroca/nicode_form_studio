<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Compiler;

use Nicode\FormStudio\Domain\CanonicalJson;
use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Domain\FormSpec;
use Nicode\FormStudio\Domain\Uuid;
use Nicode\FormStudio\Registry\FieldTypeRegistry;
use Nicode\FormStudio\Registry\ProviderRegistry;
use Nicode\FormStudio\Registry\RuleOperatorRegistry;
use Nicode\FormStudio\Registry\RuleEffectRegistry;

/** Compile the declarative runtime boundary; does not publish or mutate storage. */
final readonly class FormCompiler
{
    public const CONTAINERS = ['section', 'group', 'fieldset', 'row', 'columns', 'panel', 'step', 'repeatable-group'];
    public const PRESENTATION = ['heading', 'subheading', 'paragraph', 'safe-html', 'separator', 'spacer', 'notice', 'captcha'];
    public const EFFECTS = ['show', 'hide', 'enable', 'disable', 'required', 'optional', 'set_value', 'clear_value', 'change_options', 'filter_options', 'change_default'];

    public function __construct(
        private FieldTypeRegistry $fields,
        private ProviderRegistry $actions,
        private ProviderRegistry $dataSources,
        private ProviderRegistry $validators,
        private ?RuleOperatorRegistry $operators = null,
        private ?RuleEffectRegistry $effects = null,
    ) {
    }

    public function compile(array $draft): CompilationResult
    {
        return $this->compileDefinition($draft, false);
    }

    /** Preview can exercise repeated runtime paths without authorizing publication. */
    public function compilePreview(array $draft): CompilationResult
    {
        return $this->compileDefinition($draft, true);
    }

    private function compileDefinition(array $draft, bool $preview): CompilationResult
    {
        $diagnostics = [];
        $error = static function (string $code, string $path, string $message) use (&$diagnostics): void {
            $diagnostics[] = new Diagnostic($code, $path, $message);
        };
        if (($draft['schema_version'] ?? null) !== '1.0') {
            $error('schema.unsupported', '/schema_version', 'Unsupported FormSpec schema version.');
        }
        if (!Uuid::valid($draft['uuid'] ?? null)) { $error('form.uuid', '/uuid', 'A valid stable UUID is required.'); }
        if (!is_string($draft['name'] ?? null) || trim($draft['name']) === '') { $error('form.name', '/name', 'A form name is required.'); }
        foreach (['elements', 'fields', 'rules', 'actions'] as $key) {
            if (!isset($draft[$key]) || !is_array($draft[$key]) || !array_is_list($draft[$key])) {
                $error('schema.list', '/' . $key, 'Expected an ordered list.');
            }
        }
        // Do not attempt semantic traversal of malformed structures.
        if ($diagnostics !== []) { return new CompilationResult(null, $diagnostics); }
        $diagnostics = (new StructureValidator())->validate($draft);
        array_push($diagnostics, ...(new RuntimePolicyValidator())->validate($draft));
        if ($diagnostics === []) { array_push($diagnostics, ...\Nicode\FormStudio\Translation\DefinitionTranslations::validate($draft)); }
        if ($diagnostics !== []) { return new CompilationResult(null, $diagnostics); }
        $elements = []; $elementPositions = [];
        $layoutGraph = new DependencyGraph();
        foreach ($draft['elements'] as $i => $element) {
            $path = '/elements/' . $i;
            if (!is_array($element) || !Uuid::valid($element['uuid'] ?? null)) {
                $error('element.uuid', $path, 'Element requires a valid UUID.');
                continue;
            }
            $uuid = $element['uuid'];
            if (isset($elements[$uuid])) { $error('element.duplicate', $path, 'Duplicate element UUID.'); }
            $elements[$uuid] = $element;
            $elementPositions[$uuid] = $i;
            if (!in_array($element['type'] ?? null, [...self::CONTAINERS, ...self::PRESENTATION, 'field'], true)) { $error('element.type', $path, 'Unknown element type.'); }
            $parent = $element['parent_uuid'] ?? null;
            if ($parent !== null && !Uuid::valid($parent)) { $error('element.parent', $path . '/parent_uuid', 'Invalid parent UUID.'); }
            elseif ($parent !== null) { $layoutGraph->add($parent, $uuid); }
            foreach (($element['width'] ?? []) as $breakpoint => $width) {
                if (!in_array($breakpoint, ['desktop', 'tablet', 'mobile'], true) || !is_int($width) || $width < 1 || $width > 12) {
                    $error('layout.width', $path . '/width', 'Widths use conceptual breakpoints and integers from 1 to 12.');
                }
            }
        }
        foreach ($elements as $uuid => $element) {
            $parent = $element['parent_uuid'] ?? null;
            if ($parent !== null && (!isset($elements[$parent]) || !in_array($elements[$parent]['type'], self::CONTAINERS, true))) {
                $error('element.parent.missing', '/elements/' . $elementPositions[$uuid], 'Parent must reference an existing container.');
            }
        }
        if ($layoutGraph->cycle() !== []) { $error('layout.cycle', '/elements', 'Layout contains a cycle.'); }
        foreach ($elements as $uuid => $element) {
            $depth = in_array($element['type'] ?? null, self::CONTAINERS, true) ? 1 : 0;
            $parent = $element['parent_uuid'] ?? null; $visited = [$uuid => true];
            while ($parent !== null && isset($elements[$parent]) && !isset($visited[$parent])) {
                if (++$depth > \Nicode\FormStudio\Domain\LayoutLimits::MAX_CONTAINER_DEPTH) {
                    $error('layout.depth', '/elements/' . $elementPositions[$uuid] . '/parent_uuid', 'Layout exceeds the maximum of 64 nested containers.');
                    break;
                }
                $visited[$parent] = true; $parent = $elements[$parent]['parent_uuid'] ?? null;
            }
        }
        foreach ($elements as $uuid => $element) {
            if ($element['type'] !== 'step') { continue; }
            $parent = $element['parent_uuid'] ?? null; $visited = [];
            while ($parent !== null && isset($elements[$parent]) && !isset($visited[$parent])) {
                $visited[$parent] = true;
                if ($elements[$parent]['type'] === 'step') {
                    $error('layout.step.nested', '/elements/' . $elementPositions[$uuid] . '/parent_uuid', 'A step cannot be inside another step.');
                    break;
                }
                $parent = $elements[$parent]['parent_uuid'] ?? null;
            }
        }
        $fieldMap = []; $fieldPositions = [];
        $names = [];
        $dependencyGraph = new DependencyGraph();
        foreach ($draft['fields'] as $i => $field) {
            $path = '/fields/' . $i;
            if (!is_array($field) || !Uuid::valid($field['uuid'] ?? null)) {
                $error('field.uuid', $path, 'Field requires a valid UUID.');
                continue;
            }
            $uuid = $field['uuid'];
            if (isset($fieldMap[$uuid])) { $error('field.duplicate', $path, 'Duplicate field UUID.'); }
            $fieldMap[$uuid] = $field;
            $fieldPositions[$uuid] = $i;
            if (($elements[$uuid]['type'] ?? null) !== 'field') { $error('field.element', $path, 'Field must reference a field element with the same UUID.'); }
            $name = $field['name'] ?? '';
            if (!is_string($name) || strlen($name) > 255 || preg_match('/^[a-z][a-z0-9_]*$/D', $name) !== 1 || isset($names[$name])) {
                $error('field.name', $path . '/name', 'Machine names must be unique lowercase identifiers.');
            } else { $names[$name] = true; }
            $type = $field['type'] ?? '';
            if (!is_string($type) || !$this->fields->has($type)) { $error('field.provider', $path . '/type', 'Required field provider unavailable.'); continue; }
            $provider = $this->fields->get($type);
            $config = $field['config'] ?? [];
            if (!is_array($config)) { $error('field.config', $path . '/config', 'Expected a configuration object.'); continue; }
            array_push($diagnostics, ...$provider->validateConfiguration($config, $path . '/config'));
            if (isset($config['default'])) {
                try {
                    $value = $provider->normalize($config['default'], $config);
                    if ($provider->validate($value, $config) !== []) { $error('field.default', $path, 'Default value is invalid.'); }
                    elseif (($field['index'] ?? false) && ($field['persist'] ?? true) && \Nicode\FormStudio\Validation\IndexLimits::validate($provider->indexType(), $value, $provider->multiple()) !== []) { $error('field.default.index', $path . '/config/default', 'Default value exceeds the supported search index limits.'); }
                } catch (\InvalidArgumentException) { $error('field.default', $path, 'Default value is invalid.'); }
            }
            $index = $field['index'] ?? false;
            if ($index && $provider->indexType() === null) { $error('field.index.unsupported', $path, 'Field type does not support indexing.'); }
            if (($field['sensitive'] ?? false) && $index && !($field['allow_sensitive_index'] ?? false)) { $error('field.index.sensitive', $path, 'Sensitive indexing requires explicit approval in policy.'); }
            $seenValues = [];
            foreach ($field['options'] ?? [] as $j => $option) {
                if (!is_array($option) || !isset($option['value'], $option['label']) || !is_string($option['value']) || !is_string($option['label'])) {
                    $error('option.shape', "$path/options/$j", 'Options require separate string values and labels.');
                    continue;
                }
                if (isset($seenValues[$option['value']])) { $error('option.duplicate', "$path/options/$j", 'Duplicate option identity.'); }
                $seenValues[$option['value']] = true;
            }
            if (isset($field['source'])) {
                if ($provider->metadata()['datatype'] !== 'selection') { $error('field.source.unsupported', $path . '/source', 'Option sources require a selection field.'); }
                $this->checkProvider($this->dataSources, $field['source'], $path . '/source', $diagnostics);
            }
            if (array_key_exists('resource', $field['source'] ?? [])) {
                $resource = $field['source']['resource'];
                if (!is_array($resource) || !Uuid::valid($resource['uuid'] ?? null)
                    || !is_int($resource['revision'] ?? null) || $resource['revision'] < 1
                    || !is_string($resource['hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $resource['hash']) !== 1
                    || array_diff(array_keys($resource), ['uuid', 'revision', 'hash']) !== []) {
                    $error('source.resource', $path . '/source/resource', 'Source provenance requires a UUID, positive revision and SHA-256 hash.');
                }
            }
            if (is_string($field['source']['type'] ?? null) && $this->dataSources->has($field['source']['type'])) {
                foreach ($this->dataSources->get($field['source']['type'])->metadata()['dependency_parameters'] ?? [] as $parameter) {
                    $reference = $field['source']['config'][$parameter] ?? null;
                    if ($reference !== null && !in_array($reference, $field['source']['dependencies'] ?? [], true)) { $error('source.dependency.undeclared', "$path/source/config/$parameter", 'Provider parameter refers to an undeclared input.'); }
                }
            }
            if (in_array($field['source']['type'] ?? null, ['static', 'option_set'], true)) {
                $sourceOptions = $field['source']['config']['options'] ?? [];
                foreach (is_array($sourceOptions) ? $sourceOptions : [] as $j => $option) {
                    if (!is_array($option['when'] ?? null)) { continue; }
                    foreach (array_keys($option['when']) as $dependency) {
                        if (!in_array($dependency, $field['source']['dependencies'] ?? [], true)) { $error('source.dependency.undeclared', "$path/source/config/options/$j/when", 'Option condition refers to an undeclared input.'); }
                    }
                }
            }
            foreach ($field['validators'] ?? [] as $j => $validator) { $this->checkProvider($this->validators, $validator, "$path/validators/$j", $diagnostics); }
        }
        foreach ($elements as $uuid => $element) {
            if ($element['type'] === 'field' && !isset($fieldMap[$uuid])) { $error('element.field.missing', '/elements/' . $elementPositions[$uuid], 'Field element has no field definition.'); }
        }
        $crossValidators = $draft['validators'] ?? [];
        if (!is_array($crossValidators) || !array_is_list($crossValidators)) {
            $error('validators.list', '/validators', 'Expected validators list.');
            $crossValidators = [];
        }
        $validatorLocations = [];
        foreach ($crossValidators as $i => $validator) {
            $this->checkProvider($this->validators, $validator, '/validators/' . $i, $diagnostics);
            $validatorLocations[] = [$validator, '/validators/' . $i];
        }
        foreach ($fieldMap as $fieldUuid => $field) {
            foreach ($field['validators'] ?? [] as $i => $validator) { $validatorLocations[] = [$validator, '/fields/' . $fieldPositions[$fieldUuid] . '/validators/' . $i]; }
        }
        foreach ($validatorLocations as [$validator, $validatorPath]) {
            if (!is_array($validator) || !is_array($validator['config']['fields'] ?? null)) { continue; }
            $resolvedFields = [];
            foreach ($validator['config']['fields'] as $uuid) {
                if (!is_string($uuid) || !isset($fieldMap[$uuid])) { $error('validator.reference', $validatorPath, 'Unknown validator field reference.'); }
                elseif ($this->fields->has($fieldMap[$uuid]['type'])) {
                    $provider = $this->fields->get($fieldMap[$uuid]['type']);
                    $resolvedFields[] = ['datatype' => $provider->metadata()['datatype'], 'multiple' => $provider->multiple()];
                }
            }
            $validatorType = $validator['type'] ?? null;
            if (count($resolvedFields) === count($validator['config']['fields']) && is_string($validatorType) && $this->validators->has($validatorType)) {
                $provider = $this->validators->get($validatorType);
                if ($provider instanceof \Nicode\FormStudio\Validation\RelationalValidator) { array_push($diagnostics, ...$provider->validateFields($resolvedFields, $validatorPath)); }
            }
        }
        foreach ($fieldMap as $uuid => $field) {
            array_push($diagnostics, ...\Nicode\FormStudio\Field\Prefill::validate($field, $fieldMap, $this->fields, '/fields/' . $fieldPositions[$uuid]));
            if (($field['prefill']['type'] ?? null) === 'field' && is_string($field['prefill']['field'] ?? null) && isset($fieldMap[$field['prefill']['field']])) { $dependencyGraph->add($field['prefill']['field'], $uuid); }
            foreach ($field['source']['dependencies'] ?? [] as $dependency) {
                if (!is_string($dependency) || !isset($fieldMap[$dependency])) { $error('source.dependency', '/fields/' . $fieldPositions[$uuid], 'Unknown source dependency.'); }
                else { $dependencyGraph->add($dependency, $uuid); }
            }
        }
        $ruleIds = [];
        $conflicts = [];
        foreach ($draft['rules'] as $i => $rule) {
            $path = '/rules/' . $i;
            if (!is_array($rule) || !Uuid::valid($rule['uuid'] ?? null)) { $error('rule.uuid', $path, 'Rule requires a valid UUID.'); continue; }
            if (isset($ruleIds[$rule['uuid']])) { $error('rule.duplicate', $path, 'Duplicate rule UUID.'); }
            $ruleIds[$rule['uuid']] = true;
            if (!is_int($rule['priority'] ?? 0)) { $error('rule.priority', $path, 'Priority must be an integer.'); }
            $dependencies = $this->condition($rule['when'] ?? null, $path . '/when', $fieldMap, $diagnostics);
            if (!is_array($rule['effects'] ?? null) || !array_is_list($rule['effects'])) { $error('rule.effects', $path, 'Expected effects list.'); continue; }
            foreach ($rule['effects'] as $j => $effect) {
                $effectPath = "$path/effects/$j";
                if (!is_array($effect)) { $error('effect.shape', $effectPath, 'Invalid effect.'); continue; }
                $target = $effect['target'] ?? '';
                $type = $effect['type'] ?? '';
                if (!is_string($target) || !isset($elements[$target])) { $error('effect.target', $effectPath, 'Unknown effect target.'); continue; }
                $effectRegistry = $this->effects ?? RuleEffectRegistry::core();
                if (!is_string($type) || !$effectRegistry->has($type)) { $error('effect.type', $effectPath, 'Unknown effect type.'); continue; }
                array_push($diagnostics, ...$effectRegistry->get($type)->validateConfiguration($effect, $effectPath));
                if (!in_array($type, ['show', 'hide'], true) && !isset($fieldMap[$target])) { $error('effect.target.type', $effectPath, 'Effect requires a field target.'); }
                if (isset($fieldMap[$target]) && $this->fields->has($fieldMap[$target]['type'])) {
                    $targetProvider = $this->fields->get($fieldMap[$target]['type']);
                    if (in_array($type, ['set_value', 'change_default'], true)) {
                        try {
                            if ($targetProvider->metadata()['datatype'] === 'file') { throw new \InvalidArgumentException('Cannot assign file references.'); }
                            $targetProvider->normalize($effect['value'] ?? null, $fieldMap[$target]['config'] ?? []);
                        } catch (\InvalidArgumentException) { $error('effect.value.type', $effectPath, 'Effect value is incompatible with target field.'); }
                    }
                    if (in_array($type, ['change_options', 'filter_options'], true) && $targetProvider->metadata()['datatype'] !== 'selection') { $error('effect.options.type', $effectPath, 'Option effects require a selection field.'); }
                }
                if (in_array($type, ['set_value', 'clear_value', 'change_options', 'filter_options', 'change_default'], true)) {
                    foreach ($dependencies as $dependency) { $dependencyGraph->add($dependency, $target); }
                }
                $slot = match ($type) { 'show', 'hide' => 'visible', 'enable', 'disable' => 'enabled', 'required', 'optional' => 'required', 'set_value', 'clear_value' => 'value', 'change_options', 'filter_options' => 'options', default => $type };
                $key = ($rule['priority'] ?? 0) . ':' . CanonicalJson::encode($rule['when']) . ':' . $target . ':' . $slot;
                $signature = CanonicalJson::encode($effect);
                if (isset($conflicts[$key]) && $conflicts[$key] !== $signature) { $error('rule.conflict', $effectPath, 'Same-priority rules with identical conditions conflict.'); }
                $conflicts[$key] = $signature;
            }
        }
        if ($dependencyGraph->cycle() !== []) { $error('rule.cycle', '/rules', 'Value or data-source dependencies contain a cycle.'); }
        $actionIds = [];
        $templateTokens = ['form.name', 'form.uuid', 'submission.reference', 'submission.date', 'response.summary'];
        foreach ($fieldMap as $uuid => $field) {
            if ($field['type'] !== 'password' && ($field['include_email'] ?? !($field['sensitive'] ?? false))) {
                foreach (['value', 'label', 'option_label'] as $part) { $templateTokens[] = 'field.' . $uuid . '.' . $part; }
            }
        }
        $checkTokens = static function (string $template, array $allowed, string $path) use ($error): void {
            foreach ((new \Nicode\FormStudio\Actions\TokenTemplate())->tokens($template) as $token) {
                if (!in_array($token, $allowed, true)) { $error('template.token', $path, 'Template references an unavailable or excluded token.'); }
            }
        };
        foreach ($draft['actions'] as $i => $action) {
            if (!Uuid::valid($action['uuid'] ?? null) || isset($actionIds[$action['uuid']])) { $error('action.uuid', '/actions/' . $i, 'Action requires a unique UUID.'); }
            else { $actionIds[$action['uuid']] = true; }
            if (!in_array($action['failure_policy'] ?? 'non_blocking', ['blocking', 'non_blocking'], true)) { $error('action.failure_policy', '/actions/' . $i, 'Unknown Action failure policy.'); }
            $this->checkProvider($this->actions, $action, '/actions/' . $i, $diagnostics);
            if (in_array($action['type'] ?? '', ['email_notification', 'email_autoresponse', 'webhook'], true)) {
                foreach (['subject', 'body_text', 'body_html'] as $key) { if (is_string($action['config'][$key] ?? null)) { $checkTokens($action['config'][$key], $templateTokens, '/actions/' . $i . '/config/' . $key); } }
                foreach (['payload', 'headers'] as $key) { if (is_array($action['config'][$key] ?? null)) { foreach ($action['config'][$key] as $template) { if (is_string($template)) { $checkTokens($template, $templateTokens, '/actions/' . $i . '/config/' . $key); } } } }
            }
            if (isset($action['condition'])) { $this->condition($action['condition'], '/actions/' . $i . '/condition', $fieldMap, $diagnostics); }
            foreach (['email_field', 'reply_to_field'] as $reference) {
                if (!isset($action['config'][$reference])) { continue; }
                $target = $action['config'][$reference];
                if (!is_string($target) || !isset($fieldMap[$target]) || $fieldMap[$target]['type'] !== 'email') { $error('action.email_reference', '/actions/' . $i . '/config/' . $reference, 'Select an email field in this form.'); }
            }
            if (in_array($action['type'] ?? '', ['email_notification', 'email_autoresponse'], true) && is_array($action['config']['attachment_fields'] ?? null)) {
                foreach ($action['config']['attachment_fields'] as $index => $target) {
                    $path = '/actions/' . $i . '/config/attachment_fields/' . $index;
                    if (!is_string($target) || !isset($fieldMap[$target]) || !in_array($fieldMap[$target]['type'], ['file', 'multiple-files'], true)) {
                        $error('action.attachment_reference', $path, 'Select a file field in this form.');
                    } elseif (!($fieldMap[$target]['include_email'] ?? !($fieldMap[$target]['sensitive'] ?? false))) {
                        $error('action.attachment_policy', $path, 'The selected file field must allow inclusion in email.');
                    }
                }
            }
        }
        if (isset($draft['post_submit'])) {
            $post = $draft['post_submit'];
            if (!is_array($post)) { $error('post.shape', '/post_submit', 'Expected post-submit configuration.'); }
            else {
                if (!in_array($post['behavior'] ?? 'keep', ['keep', 'hide', 'reset'], true)) { $error('post.behavior', '/post_submit/behavior', 'Unknown success behavior.'); }
                if (isset($post['show_reference']) && !is_bool($post['show_reference'])) { $error('post.reference', '/post_submit/show_reference', 'Expected a boolean.'); }
                if (!is_array($post['preserve'] ?? []) || !array_is_list($post['preserve'] ?? [])) { $error('post.preserve', '/post_submit/preserve', 'Expected field UUID list.'); }
                else { foreach ($post['preserve'] ?? [] as $target) { if (!is_string($target) || !isset($fieldMap[$target])) { $error('post.preserve', '/post_submit/preserve', 'Unknown preserved field.'); } } }
                if (!is_array($post['summary_fields'] ?? []) || !array_is_list($post['summary_fields'] ?? [])) { $error('post.summary', '/post_submit/summary_fields', 'Expected a list of authorized summary fields.'); }
                else { foreach ($post['summary_fields'] ?? [] as $target) {
                    if (!is_string($target) || !isset($fieldMap[$target]) || ($fieldMap[$target]['sensitive'] ?? false) || in_array($fieldMap[$target]['type'], ['password', 'file', 'multiple-files'], true)) { $error('post.summary', '/post_submit/summary_fields', 'Summary fields cannot be missing, sensitive, passwords or files.'); }
                } }
                if (!is_array($post['messages'] ?? [])) { $error('post.messages', '/post_submit/messages', 'Expected message mapping.'); }
                else {
                    foreach ($post['messages'] ?? [] as $key => $message) {
                        if (!in_array($key, \Nicode\FormStudio\Translation\DefinitionTranslations::MESSAGES, true)) { $error('post.message.category', '/post_submit/messages/' . $key, 'Unsupported result message category.'); }
                        if (!is_string($message)) { $error('post.message', '/post_submit/messages/' . $key, 'Expected plain text message.'); }
                        else { $checkTokens($message, ['form.name', 'submission.reference', 'submission.date'], '/post_submit/messages/' . $key); }
                    }
                }
                if (!is_array($post['conditional_messages'] ?? []) || !array_is_list($post['conditional_messages'] ?? [])) { $error('post.conditions', '/post_submit/conditional_messages', 'Expected ordered conditional messages.'); }
                else {
                    $messageIds = [];
                    foreach ($post['conditional_messages'] ?? [] as $i => $candidate) {
                        if (!is_array($candidate) || !is_string($candidate['message'] ?? null)) { $error('post.message', '/post_submit/conditional_messages/' . $i, 'Expected message and condition.'); continue; }
                        if (isset($candidate['uuid'])) {
                            if (!Uuid::valid($candidate['uuid']) || isset($messageIds[$candidate['uuid']])) { $error('post.message.uuid', '/post_submit/conditional_messages/' . $i, 'Conditional messages require unique valid identities when supplied.'); }
                            else { $messageIds[$candidate['uuid']] = true; }
                        }
                        $this->condition($candidate['condition'] ?? null, '/post_submit/conditional_messages/' . $i, $fieldMap, $diagnostics);
                        $checkTokens($candidate['message'], ['form.name', 'submission.reference', 'submission.date'], '/post_submit/conditional_messages/' . $i);
                    }
                }
            }
        }
        try { CanonicalJson::encode($draft); }
        catch (\JsonException|\InvalidArgumentException) { $error('schema.json', '/', 'Definition is not canonical JSON data.'); }
        array_push($diagnostics, ...(new RepeatedLayoutValidator())->validate($draft));
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->severity === 'ERROR') { return new CompilationResult(null, $diagnostics); }
        }
        $initialErrors = (new SelectionDefaults($this->fields, $this->dataSources, $this->effects ?? RuleEffectRegistry::core()))->validate($draft);
        if ($initialErrors !== []) { return new CompilationResult(null, [...$diagnostics, ...$initialErrors]); }
        $dependencies = new \Nicode\FormStudio\Registry\ProviderDependencies(['fields' => $this->fields, 'actions' => $this->actions, 'sources' => $this->dataSources, 'validators' => $this->validators, 'operators' => $this->operators ?? RuleOperatorRegistry::core(), 'effects' => $this->effects ?? RuleEffectRegistry::core()]);
        try {
            $currentDependencies = $dependencies->manifest($draft);
            if (array_key_exists('provider_dependencies', $draft)) {
                if (!is_array($draft['provider_dependencies'])) { throw new \DomainException('Invalid dependencies.'); }
                $retained = [];
                foreach ($currentDependencies as $kind => $providers) {
                    if (!array_key_exists($kind, $draft['provider_dependencies'])) { continue; }
                    if (!is_array($draft['provider_dependencies'][$kind])) { throw new \DomainException('Invalid dependencies.'); }
                    $retained[$kind] = array_intersect_key($draft['provider_dependencies'][$kind], $providers);
                }
                $dependencies->compatible($retained);
            }
            $draft['provider_dependencies'] = $currentDependencies;
        } catch (\DomainException|\TypeError) { return new CompilationResult(null, [...$diagnostics, new Diagnostic('provider.compatibility', '/provider_dependencies', 'Required provider dependencies are unavailable or incompatible.')]); }
        foreach ($draft['fields'] as $i => $field) {
            if (!in_array($field['type'], ['hidden', 'system'], true) && array_key_exists('label', $field['config'] ?? []) && trim($field['config']['label']) === '') {
                $diagnostics[] = new Diagnostic('a11y.field_label', '/fields/' . $i . '/config/label', 'Provide a meaningful visible label for this field.', 'WARNING');
            }
            if ($field['type'] !== 'password' && ($field['sensitive'] ?? false) && ($field['persist'] ?? true) && ($field['index'] ?? false) && ($field['allow_sensitive_index'] ?? false)) {
                $diagnostics[] = new Diagnostic('security.sensitive_index', '/fields/' . $i . '/index', 'This sensitive value will also be stored in the search index. Review search permissions and retention.', 'WARNING');
            }
        }
        foreach (array_keys($draft['translations'] ?? []) as $locale) {
            $localized = \Nicode\FormStudio\Translation\DefinitionTranslations::resolve($draft, $locale);
            unset($localized['translations']);
            foreach ($this->compile($localized)->diagnostics as $diagnostic) {
                $diagnostics[] = new Diagnostic($diagnostic->code, '/translations/' . $locale . $diagnostic->path, $diagnostic->message, $diagnostic->severity);
            }
        }
        foreach ($diagnostics as $diagnostic) { if ($diagnostic->severity === 'ERROR') { return new CompilationResult(null, $diagnostics); } }
        return new CompilationResult(new FormSpec($draft), $diagnostics);
    }

    private function checkProvider(ProviderRegistry $registry, mixed $definition, string $path, array &$diagnostics): void
    {
        if (!is_array($definition) || !is_string($definition['type'] ?? null) || !$registry->has($definition['type'])) {
            $diagnostics[] = new Diagnostic('provider.missing', $path, 'Required provider is unavailable.');
            return;
        }
        if (!is_array($definition['config'] ?? [])) {
            $diagnostics[] = new Diagnostic('provider.config', $path, 'Expected a configuration object.');
            return;
        }
        array_push($diagnostics, ...$registry->get($definition['type'])->validateConfiguration($definition['config'] ?? [], $path . '/config'));
    }

    /** @return list<string> Field dependencies */
    private function condition(mixed $condition, string $path, array $fields, array &$diagnostics, int $depth = 0): array
    {
        if (!is_array($condition) || $depth > 64) {
            $diagnostics[] = new Diagnostic('condition.shape', $path, 'Invalid or excessively nested condition.');
            return [];
        }
        if (isset($condition['group'])) {
            if (!in_array($condition['group'], ['AND', 'OR'], true) || !is_array($condition['children'] ?? null) || !array_is_list($condition['children']) || $condition['children'] === []) {
                $diagnostics[] = new Diagnostic('condition.group', $path, 'AND/OR requires nonempty children.');
                return [];
            }
            $dependencies = [];
            foreach ($condition['children'] as $i => $child) {
                array_push($dependencies, ...$this->condition($child, "$path/children/$i", $fields, $diagnostics, $depth + 1));
            }
            return array_values(array_unique($dependencies));
        }
        $uuid = $condition['field'] ?? '';
        if (!is_string($uuid) || !isset($fields[$uuid]) || !$this->fields->has($fields[$uuid]['type'] ?? '')) {
            $diagnostics[] = new Diagnostic('condition.field', $path, 'Unknown condition field.');
            return [];
        }
        $metadata = $this->fields->get($fields[$uuid]['type'])->metadata();
        $operators = $metadata['operators'];
        $registry = $this->operators ?? RuleOperatorRegistry::core();
        $id = $condition['operator'] ?? null;
        $supportedTypes = is_string($id) && $registry->has($id) ? ($registry->get($id)->metadata()['datatypes'] ?? []) : [];
        $externalSupport = is_string($id) && $registry->has($id) && !in_array($id, \Nicode\FormStudio\Rules\CoreOperator::IDS, true)
            && is_array($supportedTypes) && in_array($metadata['datatype'], $supportedTypes, true);
        if (!in_array($id, $operators, true) && !$externalSupport) { $diagnostics[] = new Diagnostic('condition.operator', $path, 'Operator is not supported by field type.'); }
        if (!is_string($condition['operator'] ?? null) || !$registry->has($condition['operator'])) {
            $diagnostics[] = new Diagnostic('condition.provider', $path, 'Required operator unavailable.');
        } else {
            $operator = $registry->get($condition['operator']);
            $configuration = ['value' => $condition['value'] ?? null];
            if ($operator instanceof \Nicode\FormStudio\Rules\CoreOperator) { $configuration['datatype'] = $metadata['datatype']; }
            array_push($diagnostics, ...$operator->validateConfiguration($configuration, $path));
        }
        return [$uuid];
    }
}
