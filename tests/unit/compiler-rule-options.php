<?php
declare(strict_types=1);

test('rule-provided choices reject duplicate identities and nonboolean flags before publication', function (): void {
    $draft = withSecond(definition(), 'select'); [$trigger,$choice] = array_column($draft['fields'],'uuid');
    $draft['rules'] = [['uuid' => Nicode\FormStudio\Domain\Uuid::create(),'when' => ['field' => $trigger,'operator' => 'not_empty'],'effects' => [['type' => 'change_options','target' => $choice,'value' => []]]]];
    foreach (['enabled','default'] as $flag) {
        foreach (['false',0,1,null,[]] as $value) {
            $draft['rules'][0]['effects'][0]['value'] = [['value' => 'a','label' => 'A',$flag => $value]];
            $result = compiler()->compile($draft); same(false,$result->successful());
            same(['effect.option.flag'],array_column($result->diagnostics,'code'));
            same(['/rules/0/effects/0/value/0/' . $flag],array_column($result->diagnostics,'path'));
        }
    }
    $draft['rules'][0]['effects'][0]['value'] = [['value' => 'a','label' => 'First'],['value' => 'a','label' => 'Second']];
    $result = compiler()->compile($draft); same(false,$result->successful());
    same(['effect.option.duplicate'],array_column($result->diagnostics,'code'));
    same(['/rules/0/effects/0/value/1'],array_column($result->diagnostics,'path'));
    $choices = array_map(static fn (string $value): array => ['value' => $value,'label' => $value,'enabled' => true,'default' => false], ['a','A',' a ','1','01']);
    $choices[] = ['value' => 'disabled','label' => 'Disabled','enabled' => false,'default' => true];
    $draft['rules'][0]['effects'][0]['value'] = $choices;
    $result = compiler()->compile($draft); same(true,$result->successful());
    same($choices,$result->spec->toArray()['rules'][0]['effects'][0]['value']);
});
