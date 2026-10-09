<?php
declare(strict_types=1);

use Nicode\FormStudio\Contract\{BrowserProviderInterface, FieldTypeInterface};
use Nicode\FormStudio\Registry\{ProviderRegistry, RuleOperatorRegistry, RuleEffectRegistry, ValidatorRegistry};
use Nicode\FormStudio\Rendering\{BrowserProviders, PublicSpec};

trait BrowserFixtureMetadata
{
    public function version(): string { return '1.2.0'; }
    public function metadata(): array { return ['id' => $this->id(), 'version' => $this->version(), 'datatype' => 'text']; }
    public function validateConfiguration(array $configuration, string $path): array { return []; }
    public function browser(): array { return ['asset' => 'fixture.browser', 'public_config' => ['prefix', 'forbidden']]; }
}

test('browser projection exposes only installed provider module pins and declared public configuration', function (): void {
    $fields = registry();
    $fields->register(new class implements FieldTypeInterface, BrowserProviderInterface {
        use BrowserFixtureMetadata;
        public function id(): string { return 'fixture_upper'; }
        public function normalize(mixed $value, array $configuration): mixed { return $value === null ? null : strtoupper(trim((string) $value)); }
        public function validate(mixed $value, array $configuration): array { return ($configuration['required'] ?? false) && !$value ? ['required'] : []; }
        public function serialize(mixed $value): mixed { return $value; }
        public function indexType(): ?string { return 'keyword'; }
        public function multiple(): bool { return false; }
    });
    $operators = RuleOperatorRegistry::core();
    $operators->register(new class implements Nicode\FormStudio\Contract\RuleOperatorInterface, BrowserProviderInterface {
        use BrowserFixtureMetadata;
        public function id(): string { return 'fixture_suffix'; }
        public function evaluate(mixed $left, mixed $right, string $datatype): bool { return is_string($left) && str_ends_with($left, $right); }
    });
    $effects = RuleEffectRegistry::core();
    $effects->register(new class implements Nicode\FormStudio\Contract\RuleEffectInterface, BrowserProviderInterface {
        use BrowserFixtureMetadata;
        public function id(): string { return 'fixture_prefix'; }
        public function apply(array $state, array $configuration): array { $state['value'] = $configuration['prefix'] . ($state['value'] ?? ''); return $state; }
    });
    $validators = ValidatorRegistry::core();
    $validators->register(new class implements Nicode\FormStudio\Contract\ValidatorInterface, BrowserProviderInterface {
        use BrowserFixtureMetadata;
        public function id(): string { return 'fixture_forbidden'; }
        public function validate(array $values, array $configuration, array $datatypes): array { return []; }
    });
    $browser = new BrowserProviders(compact('fields', 'operators', 'effects', 'validators'), static function (string $asset): string { same('fixture.browser', $asset); return '/installed/fixture.js'; });
    $draft = definition(); $uuid = $draft['fields'][0]['uuid'];
    $draft['fields'][0]['type'] = 'fixture_upper';
    $draft['fields'][0]['config'] += ['prefix' => 'public', 'secret' => 'NEVER-PUBLIC', 'asset' => 'https://malicious.invalid/code.js'];
    $draft['rules'] = [['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'when' => ['group' => 'AND', 'children' => [['group' => 'OR', 'children' => [['field' => $uuid, 'operator' => 'fixture_suffix', 'value' => 'C']]]]], 'effects' => [['target' => $uuid, 'type' => 'fixture_prefix', 'prefix' => 'x', 'secret' => 'NEVER-PUBLIC']]]];
    $draft['validators'] = [['type' => 'fixture_forbidden', 'config' => ['forbidden' => 'ABC', 'secret' => 'NEVER-PUBLIC']]];
    $public = (new PublicSpec($fields, $browser))->project(new Nicode\FormStudio\Domain\FormSpec($draft));
    same('public', $public['fields'][0]['config']['prefix']);
    same('x', $public['rules'][0]['effects'][0]['prefix']);
    same(['forbidden' => 'ABC'], $public['validators'][0]['config']);
    foreach (['fields' => 'fixture_upper', 'operators' => 'fixture_suffix', 'effects' => 'fixture_prefix', 'validators' => 'fixture_forbidden'] as $kind => $id) { same(['version' => '1.2.0', 'module' => '/installed/fixture.js'], $public['browser_providers'][$kind][$id]); }
    same(false, str_contains(json_encode($public), 'NEVER-PUBLIC')); same(false, str_contains(json_encode($public), 'malicious.invalid'));
    same('ABC', $fields->get('fixture_upper')->normalize('  abc  ', []));
    same(['required'], $fields->get('fixture_upper')->validate(null, ['required' => true]));
    same(true, $operators->get('fixture_suffix')->evaluate('ABC', 'BC', 'text'));
    same(false, $operators->get('fixture_suffix')->evaluate(null, 'BC', 'text'));
    same('xABC', $effects->get('fixture_prefix')->apply(['value' => 'ABC'], ['prefix' => 'x'])['value']);
    raises(DomainException::class, fn () => (new BrowserProviders(['fields' => $fields], static fn () => ''))->descriptor('fields', 'fixture_upper'));
});

test('custom browser rules cannot silently fall back and validators can explicitly remain server-only', function (): void {
    $registry = new ProviderRegistry();
    $registry->register(new class implements Nicode\FormStudio\Contract\ProviderInterface {
        public function id(): string { return 'private_provider'; }
        public function version(): string { return '1.0.0'; }
        public function metadata(): array { return []; }
        public function validateConfiguration(array $configuration, string $path): array { return []; }
    });
    $browser = new BrowserProviders(['operators' => $registry, 'validators' => $registry], static fn () => '/unused');
    raises(DomainException::class, fn () => $browser->descriptor('operators', 'private_provider'));
    same(null, $browser->descriptor('validators', 'private_provider'));
});

test('installed custom operators extend declared logical types and browser styles remain trusted assets', function (): void {
    require_once __DIR__ . '/../fixtures/plg_formstudio_providerfixture/src/Provider/FixtureOperator.php';
    require_once __DIR__ . '/../fixtures/plg_formstudio_providerfixture/src/Provider/FixtureField.php';
    $operators = RuleOperatorRegistry::core(); $operators->register(new NicodeFixture\Plugin\FormStudios\ProviderFixture\Provider\FixtureOperator());
    $fields = registry(); $fields->register(new NicodeFixture\Plugin\FormStudios\ProviderFixture\Provider\FixtureField());
    $compiler = new Nicode\FormStudio\Compiler\FormCompiler($fields, new ProviderRegistry(), new ProviderRegistry(), ValidatorRegistry::core(), $operators);
    $draft = definition(); $uuid = $draft['fields'][0]['uuid'];
    $draft['rules'] = [['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'when' => ['field' => $uuid, 'operator' => 'fixture.suffix', 'value' => 'suffix'], 'effects' => [['target' => $uuid, 'type' => 'required']]]];
    same(true, $compiler->compile($draft)->successful());
    $draft['fields'][0]['type'] = 'integer'; same(false, $compiler->compile($draft)->successful());
    $resolved = [];
    $browser = new BrowserProviders(['fields' => $fields], static function (string $asset, string $kind) use (&$resolved): string { $resolved[] = [$asset, $kind]; return '/trusted.' . ($kind === 'style' ? 'css' : 'js'); });
    same(['/trusted.css'], $browser->descriptor('fields', 'fixture.upper')['styles']);
    same([['fixture.browser', 'script'], ['fixture.browser', 'style']], $resolved);
});
