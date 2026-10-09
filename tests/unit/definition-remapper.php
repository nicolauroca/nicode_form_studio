<?php
declare(strict_types=1);
use Nicode\FormStudio\Domain\{DefinitionRemapper, Uuid};

test('duplication resolves provider references to later local option identities in a complete first pass', function (): void {
    $providers = new Nicode\FormStudio\Registry\ProviderRegistry();
    $providers->register(new class implements Nicode\FormStudio\Contract\ProviderInterface {
        public function id(): string { return 'fixture.forward'; }
        public function version(): string { return '1.0.0'; }
        public function metadata(): array { return ['reference_paths' => ['/option', '/rule_option']]; }
        public function validateConfiguration(array $configuration, string $path): array { return []; }
    });
    $draft = withSecond(definition(), 'select'); $local = Uuid::create(); $dynamic = Uuid::create();
    $draft['fields'][0]['type'] = 'fixture.forward';
    $draft['fields'][0]['config'] = ['option' => $local, 'rule_option' => $dynamic, 'literal' => $local];
    $draft['fields'][1]['options'] = [['uuid' => $local, 'value' => 'local', 'label' => 'Local']];
    $effect = ['type' => 'change_options', 'target' => $draft['fields'][1]['uuid'], 'value' => [['uuid' => $dynamic, 'value' => 'dynamic', 'label' => 'Dynamic']]];
    $draft['rules'] = [['uuid' => Uuid::create(), 'when' => ['field' => $draft['fields'][0]['uuid'], 'operator' => 'not_empty'], 'effects' => [$effect]]];
    $result = (new DefinitionRemapper(['fields' => $providers]))->duplicate($draft, Uuid::create());
    $copy = $result['definition']; $map = $result['identities'];
    same($map[$local], $copy['fields'][0]['config']['option']);
    same($map[$dynamic], $copy['fields'][0]['config']['rule_option']);
    same($map[$local], $copy['fields'][1]['options'][0]['uuid']);
    same($map[$dynamic], $copy['rules'][0]['effects'][0]['value'][0]['uuid']);
    same($local, $copy['fields'][0]['config']['literal']);
});

test('duplication remaps graph identities and template references while preserving literal UUID values', function (): void {
    $draft = withSecond(definition(), 'select'); [$parent, $child] = array_column($draft['fields'], 'uuid');
    $group = Uuid::create(); $option = Uuid::create(); $resource = Uuid::create(); $rule = Uuid::create(); $action = Uuid::create();
    $draft['elements'][] = ['uuid' => $group, 'type' => 'group', 'parent_uuid' => null]; $draft['elements'][1]['parent_uuid'] = $group;
    $draft['fields'][1]['options'] = [['uuid' => $option, 'value' => $parent, 'label' => $parent]];
    $draft['fields'][1]['source'] = ['type' => 'option_set', 'dependencies' => [$parent], 'config' => ['resource_uuid' => $resource, 'revision' => 3, 'options' => [['uuid' => $option, 'value' => $parent, 'label' => 'Literal', 'when' => [$parent => $child]]]]];
    $draft['validators'] = [['type' => 'confirmation', 'config' => ['fields' => [$parent, $child]]]];
    $draft['rules'] = [['uuid' => $rule, 'when' => ['group' => 'AND', 'children' => [['field' => $parent, 'operator' => 'equals', 'value' => $child]]], 'effects' => [['type' => 'set_value', 'target' => $child, 'value' => $parent]]]];
    $draft['actions'] = [['uuid' => $action, 'type' => 'email_notification', 'condition' => ['field' => $parent, 'operator' => 'not_empty'], 'config' => ['reply_to_field' => $parent, 'subject' => '{{ field.' . $parent . '.value }}', 'body_text' => 'Literal ' . $parent]]];
    $draft['post_submit'] = ['preserve' => [$child], 'summary_fields' => [$parent, $child], 'conditional_messages' => [['condition' => ['field' => $child, 'operator' => 'equals', 'value' => $parent], 'message' => $parent]]];
    $draft['translations'] = ['es-ES' => ['fields' => [$child => ['label' => 'Selección']], 'validation' => [$child => ['required' => 'Obligatorio']], 'actions' => [$action => ['body_text' => '{{field.' . $parent . '.value}}']]]];
    $newForm = Uuid::create(); $result = (new DefinitionRemapper())->duplicate($draft, $newForm); $copy = $result['definition']; $ids = $result['identities'];
    same($newForm, $copy['uuid']); same(false, $ids[$parent] === $parent); same($ids[$parent], $copy['elements'][0]['uuid']);
    same($ids[$group], $copy['elements'][1]['parent_uuid']); same($ids[$option], $copy['fields'][1]['options'][0]['uuid']);
    same($parent, $copy['fields'][1]['options'][0]['value']); same($parent, $copy['fields'][1]['options'][0]['label']);
    same($resource, $copy['fields'][1]['source']['config']['resource_uuid']); same($option, $copy['fields'][1]['source']['config']['options'][0]['uuid']);
    same([$ids[$parent] => $child], $copy['fields'][1]['source']['config']['options'][0]['when']);
    same([$ids[$parent], $ids[$child]], $copy['validators'][0]['config']['fields']);
    same($ids[$parent], $copy['rules'][0]['when']['children'][0]['field']); same($child, $copy['rules'][0]['when']['children'][0]['value']);
    same($ids[$child], $copy['rules'][0]['effects'][0]['target']); same($parent, $copy['rules'][0]['effects'][0]['value']);
    same('{{ field.' . $ids[$parent] . '.value }}', $copy['actions'][0]['config']['subject']); same('Literal ' . $parent, $copy['actions'][0]['config']['body_text']);
    same([$ids[$child]], $copy['post_submit']['preserve']); same($ids[$child], $copy['post_submit']['conditional_messages'][0]['condition']['field']);
    same([$ids[$parent], $ids[$child]], $copy['post_submit']['summary_fields']);
    same(['label' => 'Selección'], $copy['translations']['es-ES']['fields'][$ids[$child]]);
    same(['required' => 'Obligatorio'], $copy['translations']['es-ES']['validation'][$ids[$child]]);
    same('{{field.' . $ids[$parent] . '.value}}', $copy['translations']['es-ES']['actions'][$ids[$action]]['body_text']);
    same($parent, $draft['fields'][0]['uuid']);
});

test('custom providers declare nested and wildcard identity references separately from literal configuration', function (): void {
    $sources = new Nicode\FormStudio\Registry\DataSourceRegistry();
    $sources->register(new class implements Nicode\FormStudio\Contract\DataSourceInterface {
        public function id(): string { return 'fixture.references'; }
        public function version(): string { return '1.0.0'; }
        public function metadata(): array { return ['dependency_parameters' => ['parent'], 'reference_paths' => ['/nested/*/field', '/escaped~1key'], 'template_paths' => ['/templates/*']]; }
        public function validateConfiguration(array $configuration, string $path): array { return []; }
        public function options(array $configuration, array $inputs, array $trustedContext): array { return []; }
    });
    $draft = withSecond(definition(), 'select'); $parent = $draft['fields'][0]['uuid'];
    $draft['fields'][1]['source'] = ['type' => 'fixture.references', 'dependencies' => [$parent], 'config' => ['parent' => $parent, 'nested' => [['field' => $parent]], 'escaped/key' => $parent, 'literal' => $parent, 'templates' => ['{{field.' . $parent . '.label}}']]];
    $result = (new DefinitionRemapper(['sources' => $sources]))->duplicate($draft, Uuid::create()); $new = $result['identities'][$parent]; $config = $result['definition']['fields'][1]['source']['config'];
    same($new, $config['parent']); same($new, $config['nested'][0]['field']); same($new, $config['escaped/key']); same($parent, $config['literal']); same('{{field.' . $new . '.label}}', $config['templates'][0]);
});
