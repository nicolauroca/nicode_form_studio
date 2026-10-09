<?php
declare(strict_types=1);
use Nicode\FormStudio\Domain\{Uuid, FormSpec, FieldAddress, RepeatedInstances};
use Nicode\FormStudio\Rules\RuleResult;
use Nicode\FormStudio\Rendering\PublicSpec;

test('addressed public projection scopes references and remote snapshots without exposing provider secrets', function (): void {
    [$group,$parent,$copy,$local,$remote,$one,$two] = array_map(static fn()=>Uuid::create(),range(1,7));
    $validator = ['type'=>'confirmation','config'=>['fields'=>[$parent,$copy]]];
    $fields = [
        ['uuid'=>$parent,'type'=>'text','config'=>['admin_label'=>'private-label']],
        ['uuid'=>$copy,'type'=>'text','config'=>['readonly'=>true],'prefill'=>['type'=>'field','field'=>$parent],'validators'=>[$validator]],
        ['uuid'=>$local,'type'=>'select','source'=>['type'=>'static','dependencies'=>[$parent],'config'=>['options'=>[['value'=>'x','label'=>'X','when'=>[$parent=>'ES'],'private'=>'private-option']]]]],
        ['uuid'=>$remote,'type'=>'select','source'=>['type'=>'fixture.remote','dependencies'=>[$parent],'config'=>['token'=>'private-token','endpoint'=>'private-endpoint']]],
    ];
    $elements = [['uuid'=>$group,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>2]]];
    foreach ($fields as $field) { $elements[] = ['uuid'=>$field['uuid'],'type'=>'field','parent_uuid'=>$group]; }
    $spec = new FormSpec(['schema_version'=>'1.0','uuid'=>Uuid::create(),'elements'=>$elements,'fields'=>$fields,'rules'=>[
        ['uuid'=>Uuid::create(),'when'=>['field'=>$parent,'operator'=>'equals','value'=>'hide'],'effects'=>[['target'=>$copy,'type'=>'hide']]],
    ],'validators'=>[$validator],'actions'=>[['config'=>['secret'=>'private-action']]]]);
    $rows = [$group=>[$one,$two]]; $instances = new RepeatedInstances($elements,$rows);
    $key = static fn($id,$row)=>(new FieldAddress($id,[['group'=>$group,'instance'=>$row]]))->key();
    $states = [];
    foreach ($instances->elementAddresses() as $address) { $states[$address->key()] = ['active'=>true,'options'=>[['value'=>'row-option','label'=>'Safe','private'=>'private-snapshot']]]; }
    $states[$key($remote,$two)]['active'] = false;
    $state = new RuleResult($states,[],1,[$key($copy,$one)=>true]);
    $public = (new PublicSpec(registry()))->projectInstances($spec,$rows,$state);
    $projected = array_column($public['fields'],null,'uuid');
    same($rows,$public['instances']); same(8,count($projected));
    same(['min'=>0,'max'=>2],$public['elements'][0]['repeat']);
    same($key($parent,$one),$projected[$key($copy,$one)]['prefill']['field']);
    same(false,isset($projected[$key($copy,$two)]['prefill']));
    foreach ([$one,$two] as $row) {
        same([$key($parent,$row)],$projected[$key($local,$row)]['source']['dependencies']);
        same([$key($parent,$row)=>'ES'],$projected[$key($local,$row)]['source']['config']['options'][0]['when']);
        same([$key($parent,$row),$key($copy,$row)],$projected[$key($copy,$row)]['validators'][0]['config']['fields']);
    }
    same([['value'=>'row-option','label'=>'Safe']],$projected[$key($remote,$one)]['options']);
    same([],$projected[$key($remote,$two)]['options']);
    same('remote',$projected[$key($remote,$one)]['source']['type']);
    same(2,count($public['validators'])); same(2,count($public['rules']));
    foreach ($public['rules'] as $rule) {
        $target = FieldAddress::fromKey($rule['effects'][0]['target']);
        same($key($parent,$target->instances[0]['instance']),$rule['when']['field']);
    }
    same(false,str_contains(json_encode($public),'private-'));
    unset($states[$key($parent,$one)]);
    raises(InvalidArgumentException::class,fn()=>(new PublicSpec(registry()))->projectInstances($spec,$rows,new RuleResult($states,[],1)));
});
