<?php
declare(strict_types=1);

use Nicode\FormStudio\Compiler\FormCompiler;
use Nicode\FormStudio\Registry\ProviderRegistry;
use Nicode\FormStudio\Registry\ValidatorRegistry;
use Nicode\FormStudio\Validation\ValidationEngine;

test('shared relational semantics distinguish boolean comparisons from presence counts', function (): void {
    foreach (json_decode(file_get_contents(__DIR__ . '/../fixtures/relations.json'), true, 512, JSON_THROW_ON_ERROR) as $case) {
        $config = ['fields' => ['a', 'b'], 'count' => $case['count'] ?? 0];
        $violations = ValidatorRegistry::core()->get($case['type'])->validate($case['values'], $config, ['a' => $case['datatype'], 'b' => $case['datatype']]);
        same($case['valid'], $violations === []);
        if (!$case['valid']) { same('cross.' . $case['type'], $violations[0]->code); same(['a', 'b'], $violations[0]->fields); }
    }
});

test('cross field comparison and confirmation use active canonical values', function (): void {
    $draft = withSecond(definition()); [$first, $second] = array_column($draft['fields'], 'uuid');
    $draft['validators'] = [['type' => 'confirmation', 'config' => ['fields' => [$first, $second]]]];
    $validators = ValidatorRegistry::core();
    $compiler = new FormCompiler(registry(), new ProviderRegistry(), new ProviderRegistry(), $validators);
    $result = $compiler->compile($draft); same(true, $result->successful());
    $engine = new ValidationEngine(registry(), rules(), $validators);
    same(true, $engine->validate($result->spec, [$first => ' a ', $second => 'a'])->valid());
    same([$first => ['cross.confirmation'], $second => ['cross.confirmation']], $engine->validate($result->spec, [$first => 'a', $second => 'b'])->errors);
    $draft['validators'][0]['config']['fields'][1] = 'unknown'; same(false, $compiler->compile($draft)->successful());
});
test('cross field count and range validators handle zero and exact decimals', function (): void {
    $registry = ValidatorRegistry::core();
    same([], $registry->get('at_least_one')->validate(['a' => 0, 'b' => null], ['fields' => ['a', 'b']], ['a' => 'integer', 'b' => 'integer']));
    same(1, count($registry->get('exactly_n')->validate(['a' => 0, 'b' => 1], ['fields' => ['a', 'b'], 'count' => 1], [])));
    same(1, count($registry->get('range')->validate(['a' => '9007199254740993.01', 'b' => '9007199254740993.00'], ['fields' => ['a', 'b']], ['a' => 'decimal', 'b' => 'decimal'])));
    same([], $registry->get('confirmation')->validate(['a' => 'visible'], ['fields' => ['a', 'b']], ['a' => 'text', 'b' => 'text']));
});
