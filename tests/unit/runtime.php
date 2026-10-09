<?php
declare(strict_types=1);

use Nicode\FormStudio\Domain\FormSpec;
use Nicode\FormStudio\Domain\Uuid;
use Nicode\FormStudio\Registry\RuleOperatorRegistry;
use Nicode\FormStudio\Registry\RuleEffectRegistry;
use Nicode\FormStudio\Rules\ConditionEvaluator;
use Nicode\FormStudio\Rules\RuleEngine;
use Nicode\FormStudio\Validation\ValidationEngine;

function rules(): RuleEngine { return new RuleEngine(new ConditionEvaluator(RuleOperatorRegistry::core()), RuleEffectRegistry::core(), registry()); }
function validation(): ValidationEngine { return new ValidationEngine(registry(), rules()); }
function withSecond(array $draft, string $type = 'text'): array {
    $uuid = 'c118470d-29e1-48a2-9bb0-97b9d97c0d03';
    $draft['elements'][] = ['uuid' => $uuid, 'parent_uuid' => null, 'type' => 'field'];
    $draft['fields'][] = ['uuid' => $uuid, 'name' => 'second', 'type' => $type, 'config' => ['required' => true]];
    return $draft;
}

test('selection identities preserve whitespace and reject disabled or rewritten values', function (): void {
    foreach (['select', 'radio', 'button-group', 'multiselect', 'checkbox-group'] as $type) {
        $draft = definition(); $uuid = $draft['fields'][0]['uuid']; $multiple = in_array($type, ['multiselect', 'checkbox-group'], true);
        $draft['fields'][0]['type'] = $type; $draft['fields'][0]['config'] = ['required' => true];
        $draft['fields'][0]['options'] = [['uuid' => Uuid::create(), 'value' => ' a ', 'label' => 'Allowed'], ['uuid' => Uuid::create(), 'value' => 'disabled', 'label' => 'Disabled', 'enabled' => false]];
        $spec = compiler()->compile($draft)->spec;
        $value = $multiple ? [' a ', ' a '] : ' a ';
        $result = validation()->validate($spec, [$uuid => $value]);
        same(true, $result->valid()); same($multiple ? [' a '] : ' a ', $result->values[$uuid]);
        foreach (['a', 'disabled', 'unknown'] as $invalid) { same([$uuid => ['option']], validation()->validate($spec, [$uuid => $multiple ? [$invalid] : $invalid])->errors); }
    }
});

test('multiple selection counts use distinct normalized identities and optional emptiness', function (): void {
    foreach (['multiselect', 'checkbox-group'] as $id) {
        $type = registry()->get($id); $config = ['min_selections' => 2, 'max_selections' => 2];
        foreach ([[[], []], [['a', 'a'], ['min_selections']], [['a', 'b'], []], [['a', 'b', 'c'], ['max_selections']]] as [$raw, $errors]) { same($errors, $type->validate($type->normalize($raw, $config), $config)); }
    }
});

test('consent requires an explicit accepted boolean and rejects malformed input', function (): void {
    $draft = definition(); $field = $draft['fields'][0]['uuid']; $draft['fields'][0]['type'] = 'consent';
    $spec = compiler()->compile($draft)->spec;
    foreach ([null, '', false, '0'] as $raw) { same([$field => ['required']], validation()->validate($spec, [$field => $raw])->errors); }
    foreach ([[], 'yes', ['accepted' => true]] as $raw) { same(false, validation()->validate($spec, [$field => $raw])->valid()); }
    same([$field => true], validation()->validate($spec, [$field => '1'])->values);
    $draft['fields'][0]['config']['required'] = false;
    $optional = validation()->validate(compiler()->compile($draft)->spec, [$field => '0']);
    same(true, $optional->valid()); same([$field => false], $optional->values);
});
test('server validation ignores unknown keys and rejects active required missing', function (): void {
    $spec = compiler()->compile(definition())->spec; $uuid = definition()['fields'][0]['uuid'];
    $result = validation()->validate($spec, [$uuid => ' hello ', 'unknown' => 'secret']); same(true, $result->valid()); same([$uuid => 'hello'], $result->values);
    same([$uuid => ['required']], validation()->validate($spec, [])->errors);
});
test('hidden required fields do not validate or persist supplied values', function (): void {
    $draft = withSecond(definition()); [$first, $second] = array_column($draft['fields'], 'uuid');
    $draft['rules'][] = ['uuid' => Uuid::create(), 'when' => ['field' => $first, 'operator' => 'equals', 'value' => 'hide'], 'effects' => [['target' => $second, 'type' => 'hide']]];
    $spec = compiler()->compile($draft)->spec;
    $result = validation()->validate($spec, [$first => 'hide', $second => ['malicious' => 'shape']]);
    same(true, $result->valid()); same([$first => 'hide'], $result->values);
    same([$second => ['required']], validation()->validate($spec, [$first => 'show'])->errors);
});
test('hidden container removes descendant fields', function (): void {
    $draft = withSecond(definition()); [$first, $second] = array_column($draft['fields'], 'uuid'); $group = Uuid::create();
    $draft['elements'][] = ['uuid' => $group, 'type' => 'group', 'parent_uuid' => null, 'visible' => false];
    $draft['elements'][1]['parent_uuid'] = $group;
    $result = validation()->validate(compiler()->compile($draft)->spec, [$first => 'ok', $second => 'spoof']);
    same([$first => 'ok'], $result->values); same(true, $result->valid());
});

test('disable excludes forged answers and explicit enable restores required field validation', function (): void {
    foreach ([false, true] as $initiallyDisabled) {
        $draft = withSecond(definition()); [$trigger, $answer] = array_column($draft['fields'], 'uuid');
        $target = $answer;
        $draft['fields'][1]['config']['disabled']=$initiallyDisabled;
        $draft['rules'] = [
            ['uuid'=>Uuid::create(),'priority'=>0,'when'=>['field'=>$trigger,'operator'=>'not_empty'],'effects'=>[['target'=>$target,'type'=>'disable']]],
            ['uuid'=>Uuid::create(),'priority'=>1,'when'=>['field'=>$trigger,'operator'=>'equals','value'=>'enable'],'effects'=>[['target'=>$target,'type'=>'enable']]],
        ];
        $compiled=compiler()->compile($draft); if (!$compiled->successful()) throw new RuntimeException(json_encode($compiled->diagnostics)); $spec=$compiled->spec;
        $disabled=validation()->validate($spec,[$trigger=>'disable',$answer=>['forged'=>'shape']]);
        same(true,$disabled->valid()); same([$trigger=>'disable'],$disabled->values);
        same([$answer=>['required']],validation()->validate($spec,[$trigger=>'enable'])->errors);
        $enabled=validation()->validate($spec,[$trigger=>'enable',$answer=>'Restored answer']);
        same(true,$enabled->valid()); same('Restored answer',$enabled->values[$answer]);
    }
});
test('read only values use trusted defaults and never browser values', function (): void {
    $draft = definition(); $uuid = $draft['fields'][0]['uuid']; $draft['fields'][0]['config']['readonly'] = true;
    $result = validation()->validate(compiler()->compile($draft)->spec, [$uuid => 'spoof'], [$uuid => 'trusted']); same([$uuid => 'trusted'], $result->values);
});
test('option tampering and disabled options are rejected', function (): void {
    $draft = definition(); $uuid = $draft['fields'][0]['uuid']; $draft['fields'][0]['type'] = 'select';
    $draft['fields'][0]['options'] = [['value' => 'ES', 'label' => 'Spain'], ['value' => 'FR', 'label' => 'France', 'enabled' => false]];
    $spec = compiler()->compile($draft)->spec;
    same(true, validation()->validate($spec, [$uuid => 'ES'])->valid());
    same([$uuid => ['option']], validation()->validate($spec, [$uuid => 'Spain'])->errors);
    same([$uuid => ['option']], validation()->validate($spec, [$uuid => 'FR'])->errors);
});
test('rule ordering uses priority then stable UUID independent of input order', function (): void {
    $draft = withSecond(definition()); [$first, $second] = array_column($draft['fields'], 'uuid');
    $base = ['when' => ['field' => $first, 'operator' => 'equals', 'value' => 'yes']];
    $draft['rules'] = [
        $base + ['uuid' => Uuid::create(), 'priority' => 10, 'effects' => [['target' => $second, 'type' => 'optional']]],
        $base + ['uuid' => Uuid::create(), 'priority' => 0, 'effects' => [['target' => $second, 'type' => 'required']]],
    ];
    $spec = compiler()->compile($draft)->spec; same(true, validation()->validate($spec, [$first => 'yes'])->valid());
    $draft['rules'] = array_reverse($draft['rules']); same(true, validation()->validate(compiler()->compile($draft)->spec, [$first => 'yes'])->valid());
});
test('rule engine detects nonconvergence even if a snapshot bypasses compiler', function (): void {
    $draft = definition(); $uuid = $draft['fields'][0]['uuid'];
    $draft['rules'] = [['uuid' => Uuid::create(), 'when' => ['field' => $uuid, 'operator' => 'equals', 'value' => 'yes'], 'effects' => [['target' => $uuid, 'type' => 'set_value', 'value' => 'no']]]];
    raises(DomainException::class, fn () => rules()->evaluate(new FormSpec($draft), [$uuid => 'yes']));
});
test('nested conditions use typed decimal comparison without coercing text identities', function (): void {
    $evaluator = new ConditionEvaluator(RuleOperatorRegistry::core());
    $condition = ['group' => 'AND', 'children' => [
        ['field' => 'a', 'operator' => 'greater', 'value' => '9007199254740992'],
        ['group' => 'OR', 'children' => [['field' => 'b', 'operator' => 'equals', 'value' => '01'], ['field' => 'b', 'operator' => 'equals', 'value' => '02']]],
    ]];
    same(true, $evaluator->matches($condition, ['a' => '9007199254740993', 'b' => '01'], ['a' => 'decimal', 'b' => 'text']));
    same(false, $evaluator->matches($condition, ['a' => '9007199254740993', 'b' => '1'], ['a' => 'decimal', 'b' => 'text']));
});

test('equal priority rules use lexical UUID order on both input permutations', function (): void {
    $draft = withSecond(definition()); [$first, $second] = array_column($draft['fields'], 'uuid');
    $draft['rules'] = [
        ['uuid' => '10000000-0000-4000-8000-000000000001', 'priority' => 7, 'when' => ['field' => $first, 'operator' => 'equals', 'value' => 'yes'], 'effects' => [['target' => $second, 'type' => 'required']]],
        ['uuid' => '10000000-0000-4000-8000-000000000002', 'priority' => 7, 'when' => ['field' => $first, 'operator' => 'not_empty'], 'effects' => [['target' => $second, 'type' => 'optional']]],
    ];
    foreach ([false, true] as $reverse) {
        if ($reverse) { $draft['rules'] = array_reverse($draft['rules']); }
        same(false, rules()->evaluate(compiler()->compile($draft)->spec, [$first => 'yes', $second => null])->states[$second]['required']);
    }
});
