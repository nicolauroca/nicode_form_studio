<?php
declare(strict_types=1);
test('publication rejects malformed numeric conditions before runtime evaluation', function (): void {
    $draft = definition(); $draft['fields'][0]['type'] = 'decimal'; $uuid = $draft['fields'][0]['uuid'];
    $draft['fields'][0]['config'] = [];
    $draft['rules'] = [['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'enabled' => true, 'priority' => 1, 'when' => ['field' => $uuid, 'operator' => 'greater', 'value' => 'invalid'], 'effects' => [['type' => 'required', 'target' => $uuid]]]];
    foreach ([['greater', 'invalid'], ['less', null], ['equals', []], ['equals', true], ['equals', 0.1], ['between', ['1', 'invalid']], ['in', ['1', '1e3']], ['not_in', [['1']]]] as [$operator, $value]) {
        $draft['rules'][0]['when']['operator'] = $operator; $draft['rules'][0]['when']['value'] = $value;
        same(true, in_array('operator.value.numeric', codes($draft), true));
    }
    $draft['rules'][0]['when']['operator'] = 'between'; $draft['rules'][0]['when']['value'] = ['2', '1'];
    same(true, in_array('operator.value.range', codes($draft), true));
    foreach ([['greater', '9007199254740993.01'], ['equals', null], ['in', [null, '0', '-1.25']], ['between', ['-1.25', '1.25']]] as [$operator, $value]) {
        $draft['rules'][0]['when']['operator'] = $operator; $draft['rules'][0]['when']['value'] = $value;
        same(true, compiler()->compile($draft)->successful());
    }
});
