<?php
declare(strict_types=1);
use Nicode\FormStudio\Domain\{Uuid, FormSpec, FieldAddress};
use Nicode\FormStudio\Search\IndexProjector;

test('repeated index projection separates row identity from multivalue ordinal and shares index policy', function (): void {
    [$group,$choices,$number,$secret,$transient,$one,$two] = array_map(static fn()=>Uuid::create(),range(1,7));
    $fields = [
        ['uuid'=>$choices,'type'=>'multiselect','index'=>true],
        ['uuid'=>$number,'type'=>'integer','index'=>true],
        ['uuid'=>$secret,'type'=>'text','index'=>true,'sensitive'=>true],
        ['uuid'=>$transient,'type'=>'text','index'=>true,'persist'=>false],
    ];
    $elements = [['uuid'=>$group,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>2]]];
    foreach ($fields as $field) { $elements[]=['uuid'=>$field['uuid'],'type'=>'field','parent_uuid'=>$group]; }
    $spec = new FormSpec(['schema_version'=>'1.0','elements'=>$elements,'fields'=>$fields,'rules'=>[]]);
    $key=static fn($id,$row)=>(new FieldAddress($id,[['group'=>$group,'instance'=>$row]]))->key();
    $values=[$key($choices,$one)=>['a','b'],$key($choices,$two)=>['c'],$key($number,$one)=>0,$key($number,$two)=>12,$key($secret,$one)=>'private',$key($transient,$one)=>'temporary'];
    $projector=new IndexProjector(registry());
    $projected=$projector->projectInstances($spec,[$group=>[$one,$two]],$values);
    same(5,count($projected));
    same([$choices,$choices,$number,$choices,$number],array_column($projected,'field_uuid'));
    same([0,1,0,0,0],array_column($projected,'ordinal'));
    same([$key($choices,$one),$key($choices,$one),$key($number,$one),$key($choices,$two),$key($number,$two)],array_column($projected,'field_address'));
    same($projected[0]['instance_hash'],$projected[2]['instance_hash']);
    same(false,$projected[0]['instance_hash']===$projected[3]['instance_hash']);
    same($group.'/'.$one,$projected[0]['instance_path']); same(0,$projected[2]['value_integer']);
    same(false,str_contains(json_encode($projected),'private')); same(false,str_contains(json_encode($projected),'temporary'));
    $reordered=$projector->projectInstances($spec,[$group=>[$two,$one]],$values);
    same($projected[3],$reordered[0]); same($projected[0],$reordered[2]);
    raises(InvalidArgumentException::class,fn()=>$projector->projectInstances($spec,[$group=>[$one]],$values));
    $oversized=$values; $oversized[$key($choices,$one)]=[str_repeat('x',256)];
    raises(DomainException::class,fn()=>$projector->projectInstances($spec,[$group=>[$one,$two]],$oversized));
    $many=$values; $many[$key($choices,$one)]=['a','b','c','d','e','f']; $many[$key($choices,$two)]=['g','h','i','j','k','l'];
    raises(InvalidArgumentException::class,fn()=>$projector->projectInstances($spec,[$group=>[$one,$two]],$many,10));
});
