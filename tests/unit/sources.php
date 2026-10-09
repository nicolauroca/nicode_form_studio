<?php
declare(strict_types=1);

use Nicode\FormStudio\Compiler\FormCompiler;
use Nicode\FormStudio\DataSource\OptionResolver;
use Nicode\FormStudio\DataSource\RequestCache;
use Nicode\FormStudio\DataSource\StaticDataSource;
use Nicode\FormStudio\Registry\DataSourceRegistry;
use Nicode\FormStudio\Registry\ProviderRegistry;
use Nicode\FormStudio\Registry\RuleEffectRegistry;
use Nicode\FormStudio\Registry\RuleOperatorRegistry;
use Nicode\FormStudio\Rules\ConditionEvaluator;
use Nicode\FormStudio\Rules\RuleEngine;
use Nicode\FormStudio\Validation\ValidationEngine;

final class SourceContextFixture implements Nicode\FormStudio\Contract\DataSourceInterface
{
    public int $calls = 0;
    public ?array $result = null;
    public function id(): string { return 'fixture.context'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['cache' => true, 'context_keys' => ['user_id', 'language', 'view_levels'], 'dependency_parameters' => ['parent']]; }
    public function validateConfiguration(array $configuration, string $path): array { return []; }
    public function options(array $configuration, array $inputs, array $trustedContext): array { return $this->result ?? [['value' => (string) ++$this->calls, 'label' => 'Choice']]; }
}

test('source failures notify a value-free sink without changing errors or successful resolution', function (): void {
    $provider = new SourceContextFixture(); $sources = new DataSourceRegistry(); $sources->register($provider); $calls = [];
    $resolver = new OptionResolver($sources, new RequestCache(), failureLog: static function (...$arguments) use (&$calls): void { $calls[] = $arguments; throw new RuntimeException('Unavailable log sink'); });
    $source = ['type' => $provider->id(), 'config' => ['secret' => 'not logged']];
    same('1', $resolver->resolve($source, ['private' => 'not logged'])[0]['value']); same([], $calls);
    $provider->result = [['value' => 'secret', 'label' => 'secret', 'enabled' => 'invalid']];
    raises(DomainException::class, fn () => $resolver->resolve($source, ['private' => 'not logged'])); same([[]], $calls);
});

test('malformed provider output fails closed and invalid cache entries are replaced', function (): void {
    $provider = new SourceContextFixture(); $sources = new DataSourceRegistry(); $sources->register($provider);
    $cache = new class implements Nicode\FormStudio\Contract\CacheInterface {
        public mixed $value = 'corrupt';
        public function get(string $key): mixed { return $this->value; }
        public function set(string $key, mixed $value, int $ttl): void { $this->value = $value; }
        public function delete(string $key): void { $this->value = null; }
    };
    $resolver = new OptionResolver($sources, $cache); $source = ['type' => $provider->id(), 'ttl' => 1];
    same('1', $resolver->resolve($source, [])[0]['value']); same(1, $provider->calls);
    foreach ([['value' => 'x', 'label' => 'X', 'enabled' => 'false'], ['value' => 'x', 'label' => "\xff"], ['value' => 'x', 'label' => 'X', 'default' => 1]] as $option) {
        $cache->value = null; $provider->result = [$option]; raises(DomainException::class, fn () => $resolver->resolve($source, []));
    }
});

test('source caching isolates declared context, input and TTL while ignoring unrelated values', function (): void {
    $provider = new SourceContextFixture(); $sources = new DataSourceRegistry(); $sources->register($provider);
    $resolver = new OptionResolver($sources, new RequestCache());
    $source = ['type' => $provider->id(), 'dependencies' => ['parent'], 'ttl' => 60];
    $context = ['user_id' => 1, 'language' => 'en-GB', 'view_levels' => [1]];
    $first = $resolver->resolve($source, ['parent' => 'ES'], $context);
    same($first, $resolver->resolve($source, ['parent' => 'ES', 'unrelated' => 'ignored'], $context + ['irrelevant' => true])); same(1, $provider->calls);
    $resolver->resolve($source, ['parent' => 'ES'], array_replace($context, ['user_id' => 2]));
    $resolver->resolve($source, ['parent' => 'ES'], array_replace($context, ['language' => 'es-ES']));
    $resolver->resolve($source, ['parent' => 'ES'], array_replace($context, ['view_levels' => [1, 2]]));
    $resolver->resolve(array_replace($source, ['ttl' => 1]), ['parent' => 'ES'], $context);
    $resolver->resolve($source, ['parent' => 'FR'], $context); same(6, $provider->calls);
});

test('provider-declared dependency parameters cannot bypass the source graph', function (): void {
    $sources = new DataSourceRegistry(); $sources->register(new SourceContextFixture());
    $draft = withSecond(definition(), 'select'); [$parent, $child] = array_column($draft['fields'], 'uuid');
    $draft['fields'][1]['source'] = ['type' => 'fixture.context', 'config' => ['parent' => $parent], 'dependencies' => []];
    $compiler = new FormCompiler(registry(), new ProviderRegistry(), $sources, new ProviderRegistry());
    $compiled = $compiler->compile($draft); same(false, $compiled->successful());
    same(true, in_array('source.dependency.undeclared', array_map(static fn ($diagnostic) => $diagnostic->code, $compiled->diagnostics), true));
    $draft['fields'][1]['source']['dependencies'] = [$parent]; same(true, $compiler->compile($draft)->successful());
    foreach ([-1, 86401, '60', null] as $ttl) { $draft['fields'][1]['source']['ttl'] = $ttl; same(false, $compiler->compile($draft)->successful()); }
});

test('source resource provenance is validated without a mutable resource lookup', function (): void {
    $sources = new DataSourceRegistry(); $sources->register(new StaticDataSource());
    $draft = withSecond(definition(), 'select');
    $draft['fields'][1]['source'] = ['type' => 'static', 'config' => ['options' => [['value' => 'MAD', 'label' => 'Madrid']]]];
    $compiler = new FormCompiler(registry(), new ProviderRegistry(), $sources, new ProviderRegistry());
    $pin = ['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'revision' => 1, 'hash' => str_repeat('a', 64)];
    $draft['fields'][1]['source']['resource'] = $pin;
    same(true, $compiler->compile($draft)->successful());
    foreach ([null, 'invalid', [], array_replace($pin, ['revision' => 0]), array_replace($pin, ['revision' => '1']), array_replace($pin, ['hash' => 'bad']), array_replace($pin, ['uuid' => 'bad']), $pin + ['unexpected' => true]] as $invalid) {
        $draft['fields'][1]['source']['resource'] = $invalid;
        $result = $compiler->compile($draft); same(false, $result->successful());
        same(true, in_array('source.resource', array_column($result->diagnostics, 'code'), true));
    }
});

test('request cache expires at the exact TTL boundary', function (): void {
    $now = 10; $cache = new RequestCache(static function () use (&$now): int { return $now; });
    $cache->set('a', ['value'], 3); same(['value'], $cache->get('a')); $now = 13; same(null, $cache->get('a'));
    $cache->set('a', ['other'], 4); $cache->delete('a'); same(null, $cache->get('a'));
});
test('dependent options use declared inputs and revalidate on server', function (): void {
    $sources = new DataSourceRegistry(); $sources->register(new StaticDataSource());
    $draft = withSecond(definition(), 'select'); [$country, $province] = array_column($draft['fields'], 'uuid');
    $draft['fields'][1]['source'] = ['type' => 'static', 'dependencies' => [$country], 'ttl' => 60, 'config' => ['options' => [
        ['value' => 'MAD', 'label' => 'Madrid', 'when' => [$country => 'ES']],
        ['value' => 'LIS', 'label' => 'Lisbon', 'when' => [$country => 'PT']],
    ]]];
    $compiler = new FormCompiler(registry(), new ProviderRegistry(), $sources, new ProviderRegistry());
    $result = $compiler->compile($draft); same(true, $result->successful());
    $engine = new RuleEngine(new ConditionEvaluator(RuleOperatorRegistry::core()), RuleEffectRegistry::core(), registry(), 64, new OptionResolver($sources, new RequestCache()));
    $validation = new ValidationEngine(registry(), $engine);
    same(true, $validation->validate($result->spec, [$country => 'ES', $province => 'MAD'])->valid());
    same([$province => ['option']], $validation->validate($result->spec, [$country => 'PT', $province => 'MAD'])->errors);
    same(true, $validation->validate($result->spec, [$country => 'PT', $province => 'LIS'])->valid());
    raises(DomainException::class, fn () => validation()->validate($result->spec, [$country => 'ES', $province => 'MAD']));
});
test('option defaults follow filtered and replaced options without overriding rule values', function (): void {
    foreach (['select', 'multiselect'] as $type) {
        $draft = withSecond(definition(), $type); [$trigger, $choice] = array_column($draft['fields'], 'uuid');
        $draft['fields'][1]['config'] = ['readonly' => true];
        $draft['fields'][1]['options'] = [['value' => 'a', 'label' => 'A', 'default' => true], ['value' => 'b', 'label' => 'B', 'default' => true]];
        $effects = [['type' => 'filter_options', 'target' => $choice, 'value' => ['b']]];
        $draft['rules'] = [['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'when' => ['field' => $trigger, 'operator' => 'equals', 'value' => 'yes'], 'effects' => $effects]];
        $spec = compiler()->compile($draft)->spec;
        $result = validation()->validate($spec, [$trigger => 'yes', $choice => 'forged']);
        same([], $result->errors); same($type === 'select' ? 'b' : ['b'], $result->values[$choice]);
        $draft['rules'][0]['effects'] = [['type' => 'change_options', 'target' => $choice, 'value' => [['value' => 'c', 'label' => 'C', 'default' => true]]]];
        $result = validation()->validate(compiler()->compile($draft)->spec, [$trigger => 'yes']);
        same([], $result->errors); same($type === 'select' ? 'c' : ['c'], $result->values[$choice]);
        foreach (['clear_value', 'set_value'] as $effect) {
            $draft['rules'][0]['effects'] = [['type' => $effect, 'target' => $choice, 'value' => $type === 'select' ? 'a' : ['a']], ...$effects];
            $result = validation()->validate(compiler()->compile($draft)->spec, [$trigger => 'yes']);
            if ($effect === 'clear_value') { same([], $result->errors); same($type === 'select' ? null : [], $result->values[$choice]); }
            else { same([$choice => ['option']], $result->errors); }
        }
    }
});

test('option sets require an exact revision and embedded snapshot', function (): void {
    $provider = new StaticDataSource('option_set'); same(true, count($provider->validateConfiguration([], '/source')) > 0);
    same([], $provider->validateConfiguration(['resource_uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'revision' => 1, 'options' => []], '/source'));
});

test('initial option defaults converge across dependencies without replacing submitted choices', function (): void {
    $sources = new DataSourceRegistry(); $sources->register(new StaticDataSource());
    $draft = withSecond(definition(), 'multiselect'); [$country, $province] = array_column($draft['fields'], 'uuid');
    $draft['fields'][0]['type'] = 'select'; $draft['fields'][0]['config'] = [];
    $draft['fields'][0]['options'] = [
        ['value' => 'XX', 'label' => 'Disabled', 'enabled' => false, 'default' => true],
        ['value' => 'ES', 'label' => 'Spain', 'default' => true],
        ['value' => 'FR', 'label' => 'France', 'default' => true],
    ];
    $draft['fields'][1]['config'] = [];
    $draft['fields'][1]['source'] = ['type' => 'static', 'dependencies' => [$country], 'config' => ['options' => [
        ['value' => 'MD', 'label' => 'Madrid', 'default' => true, 'when' => [$country => 'ES']],
        ['value' => 'BC', 'label' => 'Barcelona', 'default' => true, 'when' => [$country => 'ES']],
        ['value' => 'PA', 'label' => 'Paris', 'default' => true, 'when' => [$country => 'FR']],
    ]]];
    $compiler = new FormCompiler(registry(), new ProviderRegistry(), $sources, new ProviderRegistry());
    $compiled = $compiler->compile($draft); same(true, $compiled->successful());
    $engine = new RuleEngine(new ConditionEvaluator(RuleOperatorRegistry::core()), RuleEffectRegistry::core(), registry(), 64, new OptionResolver($sources, new RequestCache()));
    $initial = $engine->evaluate($compiled->spec, [$country => null, $province => []], [], [$country => true, $province => true]);
    same('ES', $initial->values[$country]); same(['MD', 'BC'], $initial->values[$province]);
    $validation = new ValidationEngine(registry(), $engine);
    $cleared = $validation->validate($compiled->spec, [$country => '', $province => []]);
    same(true, $cleared->valid()); same(null, $cleared->values[$country]); same([], $cleared->values[$province]);
    same([$province => ['option']], $validation->validate($compiled->spec, [$country => 'FR', $province => ['MD']])->errors);
    $draft['fields'][0]['config']['default'] = 'FR';
    $draft['fields'][1]['config']['readonly'] = true;
    $compiled = $compiler->compile($draft); same(true, $compiled->successful());
    $initial = $engine->evaluate($compiled->spec, [$country => 'FR', $province => []], [], [$country => true, $province => true]);
    same('FR', $initial->values[$country]); same(['PA'], $initial->values[$province]);
    $trusted = $validation->validate($compiled->spec, [$country => 'FR', $province => ['MD']]);
    same(true, $trusted->valid()); same(['PA'], $trusted->values[$province]);
    $prefilled = $validation->validate($compiled->spec, [$country => 'ES'], [$province => ['BC']]);
    same(['BC'], $prefilled->values[$province]);
});
