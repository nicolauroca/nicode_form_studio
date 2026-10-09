<?php
declare(strict_types=1);

$types = new Nicode\FormStudio\Registry\FieldTypeRegistry(); Nicode\FormStudio\Field\CoreFieldTypes::register($types);
$ids = ['name' => '1219417e-147d-4d56-a44f-3717b8a22c01', 'purpose' => '1219417e-147d-4d56-a44f-3717b8a22c02', 'email' => '1219417e-147d-4d56-a44f-3717b8a22c03', 'confirmation' => '1219417e-147d-4d56-a44f-3717b8a22c04'];
$first = '1219417e-147d-4d56-a44f-3717b8a22c05'; $second = '1219417e-147d-4d56-a44f-3717b8a22c06';
$draft = ['schema_version' => '1.0', 'uuid' => '1219417e-147d-4d56-a44f-3717b8a22c07', 'name' => 'Runtime fixture', 'elements' => [['uuid' => $first, 'type' => 'step', 'title' => 'About you', 'parent_uuid' => null], ['uuid' => $second, 'type' => 'step', 'title' => 'Contact details', 'parent_uuid' => null]], 'fields' => [], 'actions' => [], 'rules' => []];
$draft['validators'] = [['type' => 'confirmation', 'config' => ['fields' => [$ids['email'], $ids['confirmation']]]]];
foreach ($ids as $name => $uuid) {
    $draft['elements'][] = ['uuid' => $uuid, 'type' => 'field', 'parent_uuid' => in_array($name, ['name', 'purpose'], true) ? $first : $second];
    $field = ['uuid' => $uuid, 'name' => $name, 'type' => match ($name) {'purpose' => 'select', 'email', 'confirmation' => 'email', default => 'text'}, 'config' => ['label' => ucfirst($name), 'required' => true, 'visible' => !in_array($name, ['email', 'confirmation'], true)]];
    if ($name === 'purpose') { $field['config']['default'] = 'basic'; $field['options'] = [['uuid' => '1219417e-147d-4d56-a44f-3717b8a22c08', 'value' => 'basic', 'label' => 'Basic enquiry'], ['uuid' => '1219417e-147d-4d56-a44f-3717b8a22c09', 'value' => 'newsletter', 'label' => 'Newsletter']]; }
    $draft['fields'][] = $field;
}
$draft['rules'][] = ['uuid' => '1219417e-147d-4d56-a44f-3717b8a22c10', 'when' => ['field' => $ids['purpose'], 'operator' => 'equals', 'value' => 'newsletter'], 'effects' => [['target' => $ids['email'], 'type' => 'show'], ['target' => $ids['confirmation'], 'type' => 'show']]];
$validators = Nicode\FormStudio\Registry\ValidatorRegistry::core();
$operators = Nicode\FormStudio\Registry\RuleOperatorRegistry::core();
if (($_GET['browser'] ?? '') === '1') {
    require __DIR__ . '/../fixtures/plg_formstudio_providerfixture/src/Provider/FixtureOperator.php';
    require __DIR__ . '/../fixtures/plg_formstudio_providerfixture/src/Provider/FixtureField.php';
    require __DIR__ . '/../fixtures/plg_formstudio_providerfixture/src/Provider/FixtureRenderer.php';
    $operators->register(new NicodeFixture\Plugin\FormStudios\ProviderFixture\Provider\FixtureOperator());
    $types->register(new NicodeFixture\Plugin\FormStudios\ProviderFixture\Provider\FixtureField());
    $draft['fields'][0]['type'] = 'fixture.upper';
    $draft['rules'][0]['when']['operator'] = 'fixture.suffix'; $draft['rules'][0]['when']['value'] = 'letter';
}
$compiler = new Nicode\FormStudio\Compiler\FormCompiler($types, new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry(), $validators, $operators);
$compiled = $compiler->compile($draft); if (!$compiled->successful()) { throw new RuntimeException('Browser fixture compilation failed.'); }
$rules = new Nicode\FormStudio\Rules\RuleEngine(new Nicode\FormStudio\Rules\ConditionEvaluator($operators), Nicode\FormStudio\Registry\RuleEffectRegistry::core(), $types);
$renderers = new Nicode\FormStudio\Rendering\FieldRendererRegistry();
foreach (array_keys($types->metadata()) as $type) { $renderers->register($type, $type === 'fixture.upper' ? new NicodeFixture\Plugin\FormStudios\ProviderFixture\Provider\FixtureRenderer() : new Nicode\FormStudio\Rendering\CoreFieldRenderer()); }
$browser = new Nicode\FormStudio\Rendering\BrowserProviders(['fields' => $types, 'operators' => $operators, 'effects' => Nicode\FormStudio\Registry\RuleEffectRegistry::core(), 'validators' => $validators], static fn (string $name, string $type) => $type === 'style' ? '/fixture-provider.css' : '/fixture-provider.js');
$renderer = new Nicode\FormStudio\Rendering\FormRenderer($renderers, new Nicode\FormStudio\Rendering\PublicSpec($types, $browser));
$state = $rules->evaluate($compiled->spec, [$ids['purpose'] => 'basic']);
header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>FormStudio runtime fixture</title><link rel="stylesheet" href="/assets/css/formstudio.css"><script type="module" src="/assets/js/formstudio.js"></script></head><body><main><h1>FormStudio browser verification</h1><p>Local test fixture. No external messages are sent.</p>';
foreach (['alpha', 'beta'] as $instance) {
    echo '<section aria-label="Instance ' . $instance . '"><h2>Instance ' . $instance . '</h2>';
    $html = $renderer->render($compiled->spec, new Nicode\FormStudio\Rendering\RenderContext($instance, 1, 1, '/test-submit', 'fixture_csrf', 'fixture-attempt'), $state);
    if ($instance === 'alpha' && ($_GET['broken'] ?? '') === '1') {
        // Deliberate corrupt public JSON for browser isolation acceptance only.
        $html = preg_replace('~(<script type="application/json" data-nfs-definition>).*?(</script>)~s', '$1{"broken":$2', $html);
    }
    if ($instance === 'alpha' && ($_GET['missing'] ?? '') === '1') { $html = str_replace('fixture-provider.js', 'missing-provider.js', $html); }
    echo $html;
    echo '</section>';
}
echo '</main></body></html>';
