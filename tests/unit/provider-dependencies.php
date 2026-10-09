<?php
declare(strict_types=1);
use Nicode\FormStudio\Registry\{ProviderDependencies, ProviderRegistry};

function versionedFixtureRegistry(string $version): ProviderRegistry
{
    $registry = new ProviderRegistry();
    $registry->register(new class($version) implements Nicode\FormStudio\Contract\ProviderInterface {
        public function __construct(private string $v) {}
        public function id(): string { return 'text'; }
        public function version(): string { return $this->v; }
        public function metadata(): array { return ['id' => 'text', 'version' => $this->v]; }
        public function validateConfiguration(array $configuration, string $path): array { return []; }
    });
    return $registry;
}
test('provider dependencies permit compatible stable upgrades and reject missing major or prerelease drift', function (): void {
    $compiled = compiler()->compile(definition())->spec;
    same(['fields' => ['text' => '1.0.0']], $compiled->toArray()['provider_dependencies']);
    (new ProviderDependencies(['fields' => versionedFixtureRegistry('1.2.0')]))->assert($compiled);
    (new ProviderDependencies(['fields' => versionedFixtureRegistry('1.0.0+build.17')]))->assert($compiled);
    raises(DomainException::class, fn () => (new ProviderDependencies(['fields' => versionedFixtureRegistry('2.0.0')]))->assert($compiled));
    raises(DomainException::class, fn () => (new ProviderDependencies(['fields' => new ProviderRegistry()]))->assert($compiled));
    $definition = $compiled->toArray(); $definition['provider_dependencies']['fields']['text'] = '1.3.0';
    raises(DomainException::class, fn () => (new ProviderDependencies(['fields' => versionedFixtureRegistry('1.2.0')]))->assert(new Nicode\FormStudio\Domain\FormSpec($definition)));
    $definition['provider_dependencies']['fields']['text'] = '0.3.0';
    raises(DomainException::class, fn () => (new ProviderDependencies(['fields' => versionedFixtureRegistry('0.3.1')]))->assert(new Nicode\FormStudio\Domain\FormSpec($definition)));
    $definition['provider_dependencies']['fields']['text'] = '1.0.0-beta.1';
    raises(DomainException::class, fn () => (new ProviderDependencies(['fields' => versionedFixtureRegistry('1.0.0')]))->assert(new Nicode\FormStudio\Domain\FormSpec($definition)));
    (new ProviderDependencies(['fields' => versionedFixtureRegistry('1.0.0-beta.1')]))->assert(new Nicode\FormStudio\Domain\FormSpec($definition));
});
test('published contracts reject undeclared dependencies while edited drafts can remove obsolete providers', function (): void {
    $compiled = compiler()->compile(definition())->spec; $definition = $compiled->toArray();
    $definition['provider_dependencies'] = [];
    raises(DomainException::class, fn () => (new ProviderDependencies(['fields' => registry()]))->assert(new Nicode\FormStudio\Domain\FormSpec($definition)));
    $definition['provider_dependencies'] = ['fields' => ['text' => '2.0.0']];
    same(false, compiler()->compile($definition)->successful());
    $definition['provider_dependencies']['fields'] = ['removed-plugin' => '1.0.0'];
    same(true, compiler()->compile($definition)->successful());
    $definition['provider_dependencies'] = null;
    same(false, compiler()->compile($definition)->successful());
});
