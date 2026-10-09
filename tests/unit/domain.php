<?php
declare(strict_types=1);

use Nicode\FormStudio\Compiler\DependencyGraph;
use Nicode\FormStudio\Compiler\FormCompiler;
use Nicode\FormStudio\Domain\CanonicalJson;
use Nicode\FormStudio\Domain\Uuid;
use Nicode\FormStudio\Field\CoreFieldTypes;
use Nicode\FormStudio\Registry\FieldTypeRegistry;
use Nicode\FormStudio\Registry\ProviderRegistry;
use Nicode\FormStudio\Validation\Decimal;
use Nicode\FormStudio\Validation\SafePattern;

function registry(): FieldTypeRegistry { $registry = new FieldTypeRegistry(); CoreFieldTypes::register($registry); return $registry; }
function definition(): array {
    $uuid = 'b37749ed-b16b-4c6d-bbb3-d97714b7ef13';
    return ['schema_version' => '1.0', 'uuid' => 'd6f62b33-5e8b-43a9-885b-70d8b4e2f826', 'name' => 'Fixture',
        'elements' => [['uuid' => $uuid, 'type' => 'field', 'parent_uuid' => null]],
        'fields' => [['uuid' => $uuid, 'name' => 'answer', 'type' => 'text', 'config' => ['required' => true]]], 'rules' => [], 'actions' => []];
}
function compiler(): FormCompiler { return new FormCompiler(registry(), new ProviderRegistry(), new ProviderRegistry(), new ProviderRegistry()); }
function codes(array $draft): array { return array_map(static fn ($d) => $d->code, compiler()->compile($draft)->diagnostics); }

test('UUID v4 identity is valid and non-repeating', function (): void {
    $a = Uuid::create(); same(true, Uuid::valid($a)); same(false, $a === Uuid::create()); same(false, Uuid::valid('bad'));
});
test('canonical objects sort keys but ordered lists retain order', function (): void {
    same(CanonicalJson::encode(['b' => [2, 1], 'a' => ['z' => 3, 'x' => 1]]), CanonicalJson::encode(['a' => ['x' => 1, 'z' => 3], 'b' => [2, 1]]));
    same(false, CanonicalJson::encode([1, 2]) === CanonicalJson::encode([2, 1]));
});
test('compiler snapshot is deterministic and independent of edited draft', function (): void {
    $draft = definition(); $result = compiler()->compile($draft); same(true, $result->successful());
    $hash = $result->spec->hash; $draft['fields'][0]['config']['label'] = 'Changed';
    same($hash, $result->spec->hash); same(false, $hash === compiler()->compile($draft)->spec->hash);
    $copy = $result->spec->toArray(); $copy['name'] = 'Mutation'; same('Fixture', $result->spec->toArray()['name']);
});
test('compiler rejects invalid schema and malformed collection', function (): void {
    $draft = definition(); $draft['schema_version'] = '9'; same(true, in_array('schema.unsupported', codes($draft), true));
    $draft = definition(); $draft['fields'] = 'bad'; same(true, in_array('schema.list', codes($draft), true));
});
test('compiler rejects orphan fields and duplicate machine names', function (): void {
    $draft = definition(); $second = $draft['fields'][0]; $second['uuid'] = Uuid::create(); $draft['fields'][] = $second;
    same(true, in_array('field.name', codes($draft), true)); same(true, in_array('field.element', codes($draft), true));
    foreach (['', 'Uppercase', 'two words', '1answer', 'answer-name'] as $invalid) { $draft = definition(); $draft['fields'][0]['name'] = $invalid; same(true, in_array('field.name', codes($draft), true)); }
    $draft = definition(); $draft['fields'][0]['name'] = str_repeat('a', 256); same(true, in_array('field.name.length', codes($draft), true));
});
test('compiler rejects missing providers and invalid default', function (): void {
    $draft = definition(); $draft['fields'][0]['type'] = 'missing'; same(true, in_array('field.provider', codes($draft), true));
    $draft = definition(); $draft['fields'][0]['type'] = 'email'; $draft['fields'][0]['config']['default'] = 'invalid'; same(true, in_array('field.default', codes($draft), true));
});
test('compiler rejects layout cycles', function (): void {
    $draft = definition(); $uuid = Uuid::create(); $draft['elements'][] = ['uuid' => $uuid, 'type' => 'group', 'parent_uuid' => $uuid];
    same(true, in_array('layout.cycle', codes($draft), true));
});
test('dependency graph permits diamonds and detects back edges', function (): void {
    $graph = new DependencyGraph(); $graph->add('a', 'b'); $graph->add('a', 'c'); $graph->add('b', 'd'); $graph->add('c', 'd'); same([], $graph->cycle());
    $graph->add('d', 'a'); same(true, $graph->cycle() !== []);
});
test('rule compiler detects conflicting effects and value cycles', function (): void {
    $draft = definition(); $field = $draft['fields'][0]['uuid'];
    $rule = ['uuid' => Uuid::create(), 'when' => ['field' => $field, 'operator' => 'not_empty'], 'effects' => [['target' => $field, 'type' => 'required'], ['target' => $field, 'type' => 'optional']]];
    $draft['rules'] = [$rule]; same(true, in_array('rule.conflict', codes($draft), true));
    $draft['rules'][0]['effects'] = [['target' => $field, 'type' => 'set_value', 'value' => 'x']]; same(true, in_array('rule.cycle', codes($draft), true));
});
test('sensitive and nonindexable values cannot enter projection silently', function (): void {
    $draft = definition(); $draft['fields'][0]['sensitive'] = true; $draft['fields'][0]['index'] = true; same(true, in_array('field.index.sensitive', codes($draft), true));
    $draft['fields'][0]['type'] = 'password'; same(true, in_array('field.index.unsupported', codes($draft), true));
});
test('registry rejects duplicate providers and freezes', function (): void {
    $registry = registry(); raises(LogicException::class, fn () => $registry->register($registry->get('text')));
    $registry->freeze(); raises(LogicException::class, fn () => CoreFieldTypes::register($registry)); raises(DomainException::class, fn () => $registry->get('missing'));
});
test('decimal normalization and comparison remain exact at large magnitudes', function (): void {
    same('-12.34', Decimal::normalize('-00012.3400')); same('0', Decimal::normalize('-0.000'));
    same(1, Decimal::compare('9007199254740993.01', '9007199254740993.00'));
    same(-1, Decimal::compare('-0.01', '0')); same(-1, Decimal::compare('-9', '-8'));
    raises(InvalidArgumentException::class, fn () => Decimal::normalize('1e2'));
    raises(InvalidArgumentException::class, fn () => Decimal::normalize(1.2));
    same(true, Decimal::stepMatches('0.3', '0.1')); same(false, Decimal::stepMatches('0.31', '0.1'));
});
test('numeric validation handles exact min max scale precision and step', function (): void {
    $type = registry()->get('currency'); $value = $type->normalize('123.45', []);
    same([], $type->validate($value, ['min' => '100', 'max' => '200', 'step' => '0.05', 'scale' => 2, 'precision' => 5]));
    same(['max', 'step', 'scale', 'precision'], $type->validate($value, ['max' => '120', 'step' => '1', 'scale' => 1, 'precision' => 4]));
});
test('date and week validators reject normalization rollover', function (): void {
    $registry = registry(); same(['date'], $registry->get('date')->validate('2025-02-29', [])); same([], $registry->get('date')->validate('2024-02-29', []));
    same(['time'], $registry->get('time')->validate('24:00', [])); same(['week'], $registry->get('week')->validate('2021-W53', [])); same([], $registry->get('week')->validate('2020-W53', []));
});
test('normalizers reject nested inputs and preserve passwords', function (): void {
    $registry = registry(); raises(InvalidArgumentException::class, fn () => $registry->get('text')->normalize(['nested'], []));
    same('  secret  ', $registry->get('password')->normalize('  secret  ', []));
    same(['01', '1'], $registry->get('multiselect')->normalize(['01', '1', '01'], []));
    raises(InvalidArgumentException::class, fn () => $registry->get('checkbox')->normalize('anything', []));
});
test('safe pattern rejects executable or pathological grammar', function (): void {
    same(true, SafePattern::valid('[A-Z]{2}[0-9]{3}')); same(true, SafePattern::matches('[A-Z]{2}[0-9]{3}', 'AB123'));
    same(false, SafePattern::matches('[A-Z]{2}[0-9]{3}', 'AB1234')); same(false, SafePattern::valid('(a+)+')); same(false, SafePattern::valid('a++')); same(false, SafePattern::valid('(?=x)'));
});
