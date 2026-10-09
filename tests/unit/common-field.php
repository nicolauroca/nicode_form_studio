<?php
declare(strict_types=1);
test('range defaults and explicit decimal bounds agree with native slider constraints', function (): void {
    $type = registry()->get('range');
    foreach (json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/range.json'), true, flags: JSON_THROW_ON_ERROR) as $case) {
        same($case['errors'], $type->validate($case['value'], $case['config']));
    }
    foreach ([['min' => '101'], ['max' => '-1'], ['step' => '0']] as $config) { same(true, count($type->validateConfiguration($config, '/config')) > 0); }
    same([], $type->validateConfiguration(['min' => '-1.5', 'max' => '2.5', 'step' => '0.5'], '/config'));
    foreach (['min', 'max', 'step'] as $key) { same('field.configuration.numeric', registry()->get('integer')->validateConfiguration([$key => '0.5'], '/config')[0]->code); }
    same([], registry()->get('integer')->validateConfiguration(['min' => '-2', 'max' => '4', 'step' => '2'], '/config'));
    foreach (['min' => '0', 'max' => '100', 'step' => '1'] as $key => $value) { same($value, $type->metadata()['configuration_schema']['properties'][$key]['default']); }
    $html = (new Nicode\FormStudio\Rendering\CoreFieldRenderer())->render(['uuid' => 'test-range', 'name' => 'slider', 'type' => 'range', 'config' => []], 'test', '50');
    foreach (['min="0"', 'max="100"', 'step="1"'] as $attribute) { same(true, str_contains($html, $attribute)); }
});
test('common field metadata exposes initial visibility private labels and text assistance', function (): void {
    foreach (registry()->metadata() as $metadata) {
        $properties = $metadata['configuration_schema']['properties'];
        foreach (array_keys(Nicode\FormStudio\Field\CommonConfiguration::properties()) as $key) { same(true, isset($properties[$key])); }
        same(true, $properties['visible']['default']);
    }
    same(true, registry()->get('text')->metadata()['configuration_schema']['properties']['trim']['default']);
    same(false, isset(registry()->get('password')->metadata()['configuration_schema']['properties']['trim']));
});
test('common field configuration rejects reserved CSS names markup and invalid input hints', function (): void {
    foreach ([['css_class' => 'd-none'], ['css_class' => 'nfs-custom-safe" onclick="bad'], ['css_class' => implode(' ', array_fill(0, 9, 'nfs-custom-test'))], ['inputmode' => 'invalid'], ['autocomplete' => 'email" onclick="bad'], ['admin_label' => []]] as $config) {
        $draft = definition(); $draft['fields'][0]['config'] = $config;
        same(false, compiler()->compile($draft)->successful());
    }
    $draft = definition(); $draft['fields'][0]['config'] = ['css_class' => 'nfs-custom-first nfs-custom-second', 'inputmode' => 'email', 'autocomplete' => 'section-contact email', 'visible' => false];
    same(true, compiler()->compile($draft)->successful());
});
test('description and help both render safely while administrative labels stay private', function (): void {
    $draft = definition(); $uuid = $draft['fields'][0]['uuid'];
    $draft['fields'][0]['config'] = ['label' => 'Public label', 'admin_label' => 'private-admin-marker', 'description' => '<b>Description</b>', 'help' => '<em>Help</em>', 'css_class' => 'nfs-custom-contact', 'autocomplete' => 'email', 'inputmode' => 'email'];
    $spec = compiler()->compile($draft)->spec;
    $renderers = new Nicode\FormStudio\Rendering\FieldRendererRegistry(); $renderers->register('text', new Nicode\FormStudio\Rendering\CoreFieldRenderer());
    $renderer = new Nicode\FormStudio\Rendering\FormRenderer($renderers, new Nicode\FormStudio\Rendering\PublicSpec(registry()));
    $html = $renderer->render($spec, new Nicode\FormStudio\Rendering\RenderContext('common-field', 1, 2, '/index.php', 'csrf', 'attempt'), rules()->evaluate($spec, [$uuid => 'value']));
    foreach (['&lt;b&gt;Description&lt;/b&gt;', '&lt;em&gt;Help&lt;/em&gt;', 'nfs-custom-contact', 'autocomplete="email"', 'inputmode="email"'] as $text) { same(true, str_contains($html, $text)); }
    same(false, str_contains($html, 'private-admin-marker')); same(false, str_contains($html, '<b>Description'));
});
