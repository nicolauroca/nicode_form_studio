<?php
declare(strict_types=1);
use Nicode\FormStudio\Domain\{Uuid, RepeatedInstances, FieldAddress, FormSpec};
use Nicode\FormStudio\Rules\PresentationState;

test('initial repeated rows meet nested minima and retain identity through presentation and validation', function (): void {
    [$outer,$inner,$optional,$field,$unused] = array_map(static fn()=>Uuid::create(),range(1,5));
    $elements = [
        ['uuid'=>$outer,'type'=>'repeatable-group','repeat'=>['min'=>2,'max'=>3]],
        ['uuid'=>$inner,'type'=>'repeatable-group','parent_uuid'=>$outer,'repeat'=>['min'=>1,'max'=>2]],
        ['uuid'=>$field,'type'=>'field','parent_uuid'=>$inner],
        ['uuid'=>$optional,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>3]],
        ['uuid'=>$unused,'type'=>'field','parent_uuid'=>$optional],
    ];
    $initial = RepeatedInstances::initial($elements);
    $rows = $initial->declarations(); same(2,count($rows[$outer])); same([],$rows[$optional]);
    same([],$initial->minimumErrors()); same(2,count($initial->addresses()));
    $all = [];
    foreach ($rows as $list) { foreach ($list as $row) { same(true,Uuid::valid($row)); $all[]=$row; } }
    same(4,count(array_unique($all)));
    foreach ($initial->addresses() as $address) { same(2,count($address->instances)); same(true,$initial->contains($address)); }
    $fields = [['uuid'=>$field,'type'=>'text','config'=>['default'=>' seed ','required'=>true]],['uuid'=>$unused,'type'=>'text']];
    $spec = new FormSpec(['schema_version'=>'1.0','elements'=>$elements,'fields'=>$fields,'rules'=>[]]);
    $state = (new PresentationState(registry(),rules()))->evaluateInstances($spec,$rows);
    same(['seed','seed'],array_values($state->values));
    same(array_map(static fn($a)=>$a->key(),$initial->addresses()),array_keys($state->values));
    same(true,validation()->validateInstances($spec,$rows,$state->values)->valid());
    // Retry construction keeps submitted omissions; it does not silently create rows.
    $empty = new RepeatedInstances($elements,[]);
    same([$outer=>['min_instances']],$empty->minimumErrors());
    same([],$empty->addresses());
    same($rows,(new RepeatedInstances($elements,$rows))->declarations());
});

test('initial rows reject multiplicative layout expansion and excessive empty rows', function (): void {
    [$outer,$inner,$field] = array_map(static fn()=>Uuid::create(),range(1,3));
    $elements = [
        ['uuid'=>$outer,'type'=>'repeatable-group','repeat'=>['min'=>4,'max'=>4]],
        ['uuid'=>$inner,'type'=>'repeatable-group','parent_uuid'=>$outer,'repeat'=>['min'=>4,'max'=>4]],
        ['uuid'=>$field,'type'=>'field','parent_uuid'=>$inner],
    ];
    raises(InvalidArgumentException::class,fn()=>RepeatedInstances::initial($elements,20));
    array_pop($elements);
    raises(InvalidArgumentException::class,fn()=>RepeatedInstances::initial($elements,19));
    same(5,count(RepeatedInstances::initial($elements,20)->declarations()));
    $elements[0]['repeat']['min'] = 5;
    raises(InvalidArgumentException::class,fn()=>RepeatedInstances::initial($elements));
});
