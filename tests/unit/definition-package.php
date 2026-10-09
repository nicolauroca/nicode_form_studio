<?php
declare(strict_types=1);
use Nicode\FormStudio\Domain\{CanonicalJson, Uuid};
use Nicode\FormStudio\Transfer\DefinitionPackage;

test('portable definition packages embed option sets and remove credentials without submissions or runtime IDs', function (): void {
    $sources = new Nicode\FormStudio\Registry\DataSourceRegistry(); $sources->register(new Nicode\FormStudio\DataSource\StaticDataSource('option_set')); $sources->register(new Nicode\FormStudio\DataSource\StaticDataSource());
    $transport = new DefinitionPackage(['fields' => registry(), 'sources' => $sources]);
    $draft = withSecond(definition(), 'password'); $draft['fields'][0]['type'] = 'select';
    $draft['fields'][0]['source'] = ['type' => 'option_set', 'config' => ['resource_uuid' => Uuid::create(), 'revision' => 2, 'options' => [['uuid' => Uuid::create(), 'value' => 'ES', 'label' => 'España']]], 'dependencies' => []];
    $draft['fields'][1]['config']['default'] = 'private-password-fixture';
    $draft['metadata'] = ['APIKey' => 'private-key-fixture', 'accessToken' => 'private-token-fixture', 'purpose' => 'Registration'];
    $draft['submissions'] = ['private-response-fixture']; $draft['form_id'] = 123;
    $draft['provider_dependencies'] = ['fields' => ['select' => '1.0.0', 'password' => '1.0.0'], 'sources' => ['option_set' => '1.0.0']];
    $package = $transport->export($draft); $encoded = CanonicalJson::encode($package);
    foreach (['private-password-fixture', 'private-key-fixture', 'private-token-fixture', 'private-response-fixture'] as $secret) { same(false, str_contains($encoded, $secret)); }
    same(false, isset($package['definition']['form_id'])); same('static', $package['definition']['fields'][0]['source']['type']); same(false, isset($package['definition']['fields'][0]['source']['config']['resource_uuid']));
    same('1.0.0', $package['definition']['provider_dependencies']['fields']['password']); same(['static' => '1.0.0'], $package['definition']['provider_dependencies']['sources']);
    same($encoded, CanonicalJson::encode($transport->decode($encoded)));
    $reference = $transport->export($draft, 'reference-aware'); same('option_set', $reference['definition']['fields'][0]['source']['type']); same(2, $reference['definition']['fields'][0]['source']['config']['revision']);
    same('private-password-fixture', $draft['fields'][1]['config']['default']);
});

test('definition packages fail closed for corruption and unsupported schemas and strip unknown provider configuration', function (): void {
    $transport = new DefinitionPackage(['fields' => registry()]); $draft = definition();
    $draft['fields'][0]['type'] = 'fixture.unknown'; $draft['fields'][0]['config'] = ['opaque' => 'private-provider-fixture'];
    $package = $transport->export($draft); same([], $package['definition']['fields'][0]['config']); same('provider_configuration', $package['review'][0]['reason']);
    $package['definition']['name'] = 'Tampered'; raises(DomainException::class, fn () => $transport->decode(CanonicalJson::encode($package)));
    $draft['schema_version'] = '99'; raises(InvalidArgumentException::class, fn () => $transport->export($draft));
    raises(InvalidArgumentException::class, fn () => $transport->decode(str_repeat('x', 2097153)));
    $bad = definition(); $bad['fields'][0]['source'] = ['type' => 123]; raises(InvalidArgumentException::class, fn () => $transport->export($bad));
});

test('custom portability contracts still pass central credential redaction', function (): void {
    $actions = new Nicode\FormStudio\Registry\ProviderRegistry();
    $actions->register(new class implements Nicode\FormStudio\Contract\PortableProviderInterface {
        public function id(): string { return 'fixture.portable'; }
        public function version(): string { return '1.0.0'; }
        public function metadata(): array { return []; }
        public function validateConfiguration(array $configuration, string $path): array { return []; }
        public function exportConfiguration(array $configuration, string $mode): array { return ['label' => $configuration['label'], 'clientSecret' => 'accidental-provider-secret']; }
    });
    $draft = definition(); $draft['actions'] = [['uuid' => Uuid::create(), 'type' => 'fixture.portable', 'config' => ['label' => 'Portable choice', 'opaque' => 'not-exported']]];
    $package = (new DefinitionPackage(['fields' => registry(), 'actions' => $actions]))->export($draft);
    same(['label' => 'Portable choice'], $package['definition']['actions'][0]['config']); same(false, str_contains(CanonicalJson::encode($package), 'accidental-provider-secret'));
});

test('portable definitions remove sensitive rule literals without broadening conditional options', function (): void {
    $draft = withSecond(definition(), 'password'); $private = $draft['fields'][1]['uuid']; $normal = $draft['fields'][0]['uuid'];
    $draft['rules'] = [['uuid' => Uuid::create(), 'when' => ['field' => $private, 'operator' => 'equals', 'value' => 'private-condition-fixture'], 'effects' => [['type' => 'set_value', 'target' => $private, 'value' => 'private-assignment-fixture']]]];
    $draft['fields'][0]['options'] = [['uuid' => Uuid::create(), 'value' => 'normal', 'label' => 'Normal', 'when' => [$private => 'private-option-fixture']]];
    $package = (new DefinitionPackage(['fields' => registry()]))->export($draft); $json = CanonicalJson::encode($package);
    foreach (['private-condition-fixture', 'private-assignment-fixture', 'private-option-fixture'] as $literal) { same(false, str_contains($json, $literal)); }
    same(false, $package['definition']['fields'][0]['options'][0]['enabled']);
    same(false, isset($package['definition']['rules'][0]['when']['value'])); same(false, isset($package['definition']['rules'][0]['effects'][0]['value']));
    same($json, CanonicalJson::encode((new DefinitionPackage(['fields' => registry()]))->decode($json)));
});
