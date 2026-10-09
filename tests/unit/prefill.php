<?php
declare(strict_types=1);
use Nicode\FormStudio\Field\Prefill;
use Nicode\FormStudio\Validation\ValidationEngine;

test('initial prefill normalization clears invalid external values without concealing invalid defaults', function (): void {
    $integer = registry()->get('integer');
    $field = ['config' => ['required' => true, 'min' => 1, 'max' => 10], 'prefill' => ['type' => 'query', 'key' => 'number']];
    same(null, Prefill::normalizeInitial($integer, $field, 'invalid'));
    same(null, Prefill::normalizeInitial($integer, $field, '11'));
    same(7, Prefill::normalizeInitial($integer, $field, '7'));
    same(null, Prefill::normalizeInitial($integer, $field, null));
    unset($field['prefill']);
    $thrown = false;
    try { Prefill::normalizeInitial($integer, $field, 'invalid'); }
    catch (InvalidArgumentException) { $thrown = true; }
    same(true, $thrown);
});

test('prefill permits explicit user and context properties without treating URL values as authority', function (): void {
    $draft = definition(); $uuid = $draft['fields'][0]['uuid'];
    $draft['fields'][0]['prefill'] = ['type' => 'user', 'property' => 'email'];
    $draft['fields'][0]['config']['readonly'] = true;
    $spec = compiler()->compile($draft)->spec; same(true, $spec !== null);
    $context = ['user_id' => 3, 'user_properties' => ['email' => 'trusted@example.test'], 'prefill_query' => ['email' => 'forged@example.test']];
    $result = (new ValidationEngine(registry(), rules()))->validate($spec, [$uuid => 'forged@example.test'], [], $context);
    same('trusted@example.test', $result->values[$uuid]);
    same(null, Prefill::value($draft['fields'][0], array_replace($context, ['user_id' => 0])));
    $draft['fields'][0]['prefill'] = ['type' => 'query', 'key' => 'email']; same(false, compiler()->compile($draft)->successful());
    $draft['fields'][0]['config']['readonly'] = false; same(true, compiler()->compile($draft)->successful());
    same('forged@example.test', Prefill::value($draft['fields'][0], $context));
    $draft['fields'][0]['prefill'] = ['type' => 'user', 'property' => 'password']; same(false, compiler()->compile($draft)->successful());
    $draft['fields'][0]['prefill'] = ['type' => 'context', 'key' => 'department']; same('Approved', Prefill::value($draft['fields'][0], ['prefill_context' => ['department' => 'Approved']]));
});

test('field prefill participates in dependency checks and is recalculated only when authoritative', function (): void {
    $draft = withSecond(definition()); [$first, $second] = array_column($draft['fields'], 'uuid');
    $draft['fields'][1]['prefill'] = ['type' => 'field', 'field' => $first]; $draft['fields'][1]['config']['readonly'] = true;
    $spec = compiler()->compile($draft)->spec; same(true, $spec !== null);
    $engine = new ValidationEngine(registry(), rules());
    same('canonical', $engine->validate($spec, [$first => ' canonical ', $second => 'forged'])->values[$second]);
    same('trusted override', $engine->validate($spec, [$first => 'source', $second => 'forged'], [$second => 'trusted override'])->values[$second]);
    $display = rules()->evaluate($spec, [$first => 'source', $second => 'trusted override'], [], [$first => true]);
    $projection = (new Nicode\FormStudio\Rendering\PublicSpec(registry()))->project($spec, $display);
    same('trusted override', $display->values[$second]); same(false, isset($projection['fields'][1]['prefill']));
    $display = rules()->evaluate($spec, [$first => 'source', $second => null], [], [$first => true, $second => true]);
    same($first, (new Nicode\FormStudio\Rendering\PublicSpec(registry()))->project($spec, $display)['fields'][1]['prefill']['field']);
    $draft['fields'][0]['prefill'] = ['type' => 'field', 'field' => $second]; same(false, compiler()->compile($draft)->successful()); unset($draft['fields'][0]['prefill']);
    $draft['fields'][0]['sensitive'] = true; same(false, compiler()->compile($draft)->successful()); unset($draft['fields'][0]['sensitive']);
    $draft['fields'][1]['config']['readonly'] = false;
    same('edited', $engine->validate(compiler()->compile($draft)->spec, [$first => 'source', $second => 'edited'])->values[$second]);
    $draft['fields'][1]['prefill'] = ['type' => 'constant', 'value' => 'private-prefill']; $draft['fields'][1]['sensitive'] = true;
    $package = (new Nicode\FormStudio\Transfer\DefinitionPackage(['fields' => registry()]))->export($draft);
    same(false, str_contains(json_encode($package), 'private-prefill'));
});

test('derived provider normalization failures become field errors and permit explicit rule recovery', function (): void {
    $types = registry();
    $types->register(new class implements Nicode\FormStudio\Contract\FieldTypeInterface {
        private Nicode\FormStudio\Field\ScalarFieldType $base;
        public function __construct() { $this->base = new Nicode\FormStudio\Field\ScalarFieldType('text', 'text', 'keyword'); }
        public function id(): string { return 'fixture.strict-copy'; }
        public function version(): string { return '1.0.0'; }
        public function metadata(): array { return array_replace($this->base->metadata(), ['id' => $this->id()]); }
        public function validateConfiguration(array $configuration, string $path): array { return $this->base->validateConfiguration($configuration, $path); }
        public function normalize(mixed $value, array $configuration): mixed { if ($value === 'reject') { throw new InvalidArgumentException('Private raw input must not escape.'); } return $this->base->normalize($value, $configuration); }
        public function validate(mixed $value, array $configuration): array { return $this->base->validate($value, $configuration); }
        public function serialize(mixed $value): mixed { return $value; }
        public function indexType(): ?string { return 'keyword'; }
        public function multiple(): bool { return false; }
    });
    $compiler = new Nicode\FormStudio\Compiler\FormCompiler($types, new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry());
    $engine = new Nicode\FormStudio\Rules\RuleEngine(new Nicode\FormStudio\Rules\ConditionEvaluator(Nicode\FormStudio\Registry\RuleOperatorRegistry::core()), Nicode\FormStudio\Registry\RuleEffectRegistry::core(), $types);
    $validator = new ValidationEngine($types, $engine);
    $draft = withSecond(definition(), 'fixture.strict-copy'); [$source, $target] = array_column($draft['fields'], 'uuid');
    $draft['fields'][1]['config'] = ['readonly' => true, 'required' => false];
    $draft['fields'][1]['prefill'] = ['type' => 'field', 'field' => $source];
    $compiled = $compiler->compile($draft); same(true, $compiled->successful());
    same([$target => ['type']], $validator->validate($compiled->spec, [$source => 'reject'])->errors);
    $display = $engine->evaluate($compiled->spec, [$source => 'reject', $target => null], [], [$target => true]);
    same(null, $display->values[$target]);
    same([$target => ['type']], $display->normalizationErrors);
    same([], $validator->validate($compiled->spec, [$source => 'accepted'])->errors);
    foreach (['set_value', 'clear_value', 'hide'] as $effect) {
        $draft['rules'] = [['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'when' => ['field' => $source, 'operator' => 'equals', 'value' => 'reject'], 'effects' => [['type' => $effect, 'target' => $target, 'value' => 'recovered']]]];
        same([], $validator->validate($compiler->compile($draft)->spec, [$source => 'reject'])->errors);
    }
});
