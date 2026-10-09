<?php
declare(strict_types=1);

test('option source targets must expose the selection datatype', function (): void {
    $fields = registry(); $fields->register(new Nicode\FormStudio\Field\ScalarFieldType('custom_choice','selection','keyword'));
    $sources = new Nicode\FormStudio\Registry\DataSourceRegistry(); $sources->register(new Nicode\FormStudio\DataSource\StaticDataSource());
    $compiler = new Nicode\FormStudio\Compiler\FormCompiler($fields,new Nicode\FormStudio\Registry\ProviderRegistry(),$sources,new Nicode\FormStudio\Registry\ProviderRegistry());
    foreach (['text','integer','date','checkbox','select','multiselect','radio','checkbox-group','button-group','custom_choice'] as $type) {
        $draft = definition(); $draft['fields'][0]['type'] = $type;
        $draft['fields'][0]['source'] = ['type' => 'static','config' => ['options' => [['value' => 'a','label' => 'Choice']]]];
        $result = $compiler->compile($draft); $supported = $fields->get($type)->metadata()['datatype'] === 'selection';
        same($supported,$result->successful());
        if (!$supported) { same(['field.source.unsupported'],array_column($result->diagnostics,'code')); same(['/fields/0/source'],array_column($result->diagnostics,'path')); }
    }
});

test('publication validates copied source provenance option snapshots and declared dependencies', function (): void {
    $sources = new Nicode\FormStudio\Registry\DataSourceRegistry();
    $sources->register(new Nicode\FormStudio\DataSource\StaticDataSource());
    $sources->register(new Nicode\FormStudio\DataSource\StaticDataSource('option_set'));
    $compiler = new Nicode\FormStudio\Compiler\FormCompiler(registry(), new Nicode\FormStudio\Registry\ProviderRegistry(), $sources, new Nicode\FormStudio\Registry\ProviderRegistry());
    $base = withSecond(definition(), 'select'); [$input,$target] = array_column($base['fields'],'uuid');
    $resource = ['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'revision' => 3, 'hash' => str_repeat('a',64)];
    $source = ['type' => 'option_set', 'dependencies' => [$input], 'resource' => $resource, 'config' => ['resource_uuid' => $resource['uuid'], 'revision' => 3, 'resource_hash' => $resource['hash'], 'options' => [['value' => ' A ', 'label' => 'Copied label', 'when' => [$input => 'ES']]]]];
    $compile = static function (array $candidate) use ($compiler,$base) {
        $draft = $base; $draft['fields'][1]['source'] = $candidate; return $compiler->compile($draft);
    };
    $valid = $compile($source); same(true,$valid->successful());
    same($source,$valid->spec->toArray()['fields'][1]['source']);
    $originalJson = $valid->spec->json;
    $cases = [
        ['source.resource', static function (array &$s): void { $s['resource']['revision'] = 0; }],
        ['source.resource', static function (array &$s): void { $s['resource']['hash'] = 'bad'; }],
        ['source.resource', static function (array &$s): void { $s['resource']['unknown'] = true; }],
        ['source.resource', static function (array &$s): void { $s['config']['revision'] = '3'; }],
        ['source.resource', static function (array &$s): void { $s['config']['resource_uuid'] = 'missing'; }],
        ['source.resource.hash', static function (array &$s): void { $s['config']['resource_hash'] = 'bad'; }],
        ['source.options', static function (array &$s): void { unset($s['config']['options']); }],
        ['source.option.duplicate', static function (array &$s): void { $s['config']['options'][] = $s['config']['options'][0]; }],
        ['source.option.flag', static function (array &$s): void { $s['config']['options'][0]['enabled'] = 'yes'; }],
        ['source.option.condition', static function (array &$s) use ($input): void { $s['config']['options'][0]['when'][$input] = ['ES']; }],
        ['source.dependency.undeclared', static function (array &$s): void { $s['dependencies'] = []; }],
        ['source.dependency', static function (array &$s): void { $s['dependencies'][] = Nicode\FormStudio\Domain\Uuid::create(); }],
    ];
    foreach ($cases as [$code,$mutate]) {
        $candidate = $source; $mutate($candidate); $result = $compile($candidate);
        same(false,$result->successful());
        same(true,in_array($code,array_column($result->diagnostics,'code'),true));
    }
    // Provenance is a record of the bound copy, not a live resource lookup.
    $source['config']['options'][0]['label'] = 'Later authoring label';
    same(true,$compile($source)->successful()); same($originalJson,$valid->spec->json);
});
