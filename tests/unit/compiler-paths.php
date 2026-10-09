<?php
declare(strict_types=1);

test('semantic diagnostics use list indices that resolve to the affected draft node', function (): void {
    $cases = [
        ['element.parent.missing', '/elements/0', static function (array &$draft): void { $draft['elements'][0]['parent_uuid'] = Nicode\FormStudio\Domain\Uuid::create(); }],
        ['element.field.missing', '/elements/0', static function (array &$draft): void { $draft['fields'] = []; }],
        ['field.prefill', '/fields/0/prefill', static function (array &$draft): void { $draft['fields'][0]['prefill'] = ['type' => 'field', 'field' => Nicode\FormStudio\Domain\Uuid::create()]; }],
        ['source.dependency', '/fields/0', static function (array &$draft): void { $draft['fields'][0]['source'] = ['type' => 'missing', 'dependencies' => [Nicode\FormStudio\Domain\Uuid::create()]]; }],
    ];
    foreach ($cases as [$code, $path, $mutate]) {
        $draft = definition(); $mutate($draft); $result = compiler()->compile($draft);
        same(false, $result->successful());
        $matches = array_values(array_filter($result->diagnostics, static fn ($diagnostic): bool => $diagnostic->code === $code));
        same(1, count($matches)); same($path, $matches[0]->path);
        $node = $draft;
        foreach (explode('/', substr($path, 1)) as $segment) {
            same(true, array_key_exists($segment, $node)); $node = $node[$segment];
        }
        same(true, is_array($node));
    }
});

test('field validator reference failures retain their owning field path beside form validators', function (): void {
    $draft = withSecond(definition()); [$first, $second] = array_column($draft['fields'], 'uuid');
    $draft['validators'] = [['type' => 'confirmation', 'config' => ['fields' => [$first, $second]]]];
    $draft['fields'][1]['validators'] = [['type' => 'confirmation', 'config' => ['fields' => [$second, Nicode\FormStudio\Domain\Uuid::create()]]]];
    $compiler = new Nicode\FormStudio\Compiler\FormCompiler(registry(), new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry(), Nicode\FormStudio\Registry\ValidatorRegistry::core());
    $result = $compiler->compile($draft); same(false, $result->successful());
    same(['validator.reference'], array_column($result->diagnostics, 'code'));
    same(['/fields/1/validators/0'], array_column($result->diagnostics, 'path'));
    $draft['fields'][1]['validators'][0]['config']['fields'][1] = $first;
    same(true, $compiler->compile($draft)->successful());
});
