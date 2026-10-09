<?php
declare(strict_types=1);
use Nicode\FormStudio\Domain\{Uuid, FormSpec, FieldAddress, RepeatedInstances};
use Nicode\FormStudio\Validation\ValidationEngine;
use Nicode\FormStudio\Registry\ValidatorRegistry;

test('repeated submission validation derives readonly values and applies relational checks per row', function (): void {
    [$group,$left,$right,$derived,$one,$two] = array_map(static fn()=>Uuid::create(),range(1,6));
    $elements = [['uuid'=>$group,'type'=>'repeatable-group','repeat'=>['min'=>1,'max'=>2]]];
    foreach ([$left,$right,$derived] as $id) { $elements[] = ['uuid'=>$id,'type'=>'field','parent_uuid'=>$group]; }
    $validator = ['type'=>'confirmation','config'=>['fields'=>[$left,$right]]];
    $data = ['schema_version'=>'1.0','elements'=>$elements,'fields'=>[
        ['uuid'=>$left,'type'=>'text','config'=>['required'=>true]],
        ['uuid'=>$right,'type'=>'text','validators'=>[$validator]],
        ['uuid'=>$derived,'type'=>'text','config'=>['readonly'=>true],'prefill'=>['type'=>'field','field'=>$left]],
    ],'rules'=>[],'validators'=>[$validator]];
    $key = static fn($id,$row)=>(new FieldAddress($id,[['group'=>$group,'instance'=>$row]]))->key();
    $rows = [$group=>[$one,$two]];
    $raw = [$key($left,$one)=>' A ',$key($right,$one)=>'A',$key($left,$two)=>'B',$key($right,$two)=>'C',$key($derived,$one)=>['forged'],$key($derived,$two)=>'forged'];
    $engine = new ValidationEngine(registry(),rules(),ValidatorRegistry::core());
    $result = $engine->validateInstances(new FormSpec($data),$rows,$raw);
    same('A',$result->values[$key($derived,$one)]); same('B',$result->values[$key($derived,$two)]);
    same([$key($left,$two)=>['cross.confirmation'],$key($right,$two)=>['cross.confirmation']],$result->errors);
    // Explicit trusted null suppresses derived/default replacement in just this row.
    $result = $engine->validateInstances(new FormSpec($data),$rows,$raw,[$key($derived,$one)=>null]);
    same(null,$result->values[$key($derived,$one)]); same('B',$result->values[$key($derived,$two)]);
    unset($raw[$key($left,$one)]);
    $result = $engine->validateInstances(new FormSpec($data),$rows,$raw);
    same(['required'],$result->errors[$key($left,$one)]);
    raises(InvalidArgumentException::class,fn()=>$engine->validateInstances(new FormSpec($data),$rows,$raw,[$derived=>'wrong scope']));
    $data['rules'] = [['uuid'=>Uuid::create(),'when'=>['field'=>$left,'operator'=>'equals','value'=>'B'],'effects'=>[['target'=>$right,'type'=>'hide']]]];
    $result = $engine->validateInstances(new FormSpec($data),$rows,$raw);
    same(false,isset($result->errors[$key($right,$two)]));
    same(false,array_key_exists($key($right,$two),$result->values));
    $data['validators'] = array_fill(0,5,$validator);
    raises(InvalidArgumentException::class,fn()=>$engine->validateInstances(new FormSpec($data),$rows,$raw,[],[],8));
});

test('repeated minimum counts only constrain active group scopes including empty nested groups', function (): void {
    [$outer,$inner,$toggle,$field,$one,$two] = array_map(static fn()=>Uuid::create(),range(1,6));
    $data = ['schema_version'=>'1.0','elements'=>[
        ['uuid'=>$outer,'type'=>'repeatable-group','repeat'=>['min'=>1,'max'=>2]],
        ['uuid'=>$toggle,'type'=>'field','parent_uuid'=>$outer],
        ['uuid'=>$inner,'type'=>'repeatable-group','repeat'=>['min'=>1,'max'=>2],'parent_uuid'=>$outer],
        ['uuid'=>$field,'type'=>'field','parent_uuid'=>$inner],
    ],'fields'=>[['uuid'=>$toggle,'type'=>'text'],['uuid'=>$field,'type'=>'text','config'=>['required'=>true]]],
    'rules'=>[['uuid'=>Uuid::create(),'when'=>['field'=>$toggle,'operator'=>'equals','value'=>'hide'],'effects'=>[['target'=>$inner,'type'=>'hide']]]]];
    $key = static fn($id,$row)=>(new FieldAddress($id,[['group'=>$outer,'instance'=>$row]]))->key();
    $engine = new ValidationEngine(registry(),rules(),ValidatorRegistry::core());
    $result = $engine->validateInstances(new FormSpec($data),[$outer=>[$one,$two]],[$key($toggle,$one)=>'hide',$key($toggle,$two)=>'show']);
    same([$key($inner,$two)=>['min_instances']],$result->errors);
    same([$outer=>['min_instances']],$engine->validateInstances(new FormSpec($data),[],[])->errors);
    $data['elements'][0]['visible'] = false;
    same([],$engine->validateInstances(new FormSpec($data),[],[])->errors);
});

test('form validator contexts use deepest comparable scope and reject sibling ambiguity without rows', function (): void {
    [$outer,$inner,$other,$root,$parent,$child,$sibling,$one,$two] = array_map(static fn()=>Uuid::create(),range(1,9));
    $elements = [
        ['uuid'=>$root,'type'=>'field'],
        ['uuid'=>$outer,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>1]],
        ['uuid'=>$parent,'type'=>'field','parent_uuid'=>$outer],
        ['uuid'=>$inner,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>1],'parent_uuid'=>$outer],
        ['uuid'=>$child,'type'=>'field','parent_uuid'=>$inner],
        ['uuid'=>$other,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>1]],
        ['uuid'=>$sibling,'type'=>'field','parent_uuid'=>$other],
    ];
    $innerKey = (new FieldAddress($inner,[['group'=>$outer,'instance'=>$one]]))->key();
    $instances = new RepeatedInstances($elements,[$outer=>[$one],$innerKey=>[$two]]);
    $expected = (new FieldAddress($child,[['group'=>$outer,'instance'=>$one],['group'=>$inner,'instance'=>$two]]))->key();
    foreach ([[$root,$parent,$child],[$child,$root,$parent]] as $refs) { same([$expected],array_map(static fn($a)=>$a->key(),$instances->referenceContexts($refs))); }
    same([$root],array_map(static fn($a)=>$a->key(),$instances->referenceContexts([$root])));
    raises(InvalidArgumentException::class,fn()=>(new RepeatedInstances($elements,[]))->referenceContexts([$child,$sibling]));
});
