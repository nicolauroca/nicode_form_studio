<?php
declare(strict_types=1);
use Nicode\FormStudio\Domain\{Uuid, FormSpec, FieldAddress};
use Nicode\FormStudio\Submission\StoredValues;
use Nicode\FormStudio\Validation\ValidationResult;

test('stored repeated values apply persistence policy and consent evidence independently by address', function (): void {
    [$group,$text,$password,$transient,$consent,$one,$two] = array_map(static fn()=>Uuid::create(),range(1,7));
    $elements = [['uuid'=>$group,'type'=>'repeatable-group','repeat'=>['min'=>1,'max'=>2]]];
    $fields=[['uuid'=>$text,'type'=>'text'],['uuid'=>$password,'type'=>'password'],['uuid'=>$transient,'type'=>'text','persist'=>false],['uuid'=>$consent,'type'=>'consent','config'=>['label'=>'Terms','required'=>false]]];
    foreach($fields as $field) $elements[]=['uuid'=>$field['uuid'],'type'=>'field','parent_uuid'=>$group];
    $data=['schema_version'=>'1.0','elements'=>$elements,'fields'=>$fields,'rules'=>[]];
    $rows=[$group=>[$two,$one]];
    $key=static fn($id,$row)=>(new FieldAddress($id,[['group'=>$group,'instance'=>$row]]))->key();
    $raw=[];
    foreach([$one,$two] as $row) {
        $raw[$key($text,$row)]=$row===$one?'  A  ':' B ';
        $raw[$key($password,$row)]='secret'; $raw[$key($transient,$row)]='temporary'; $raw[$key($consent,$row)]=$row===$one;
    }
    $spec=new FormSpec($data); $valid=validation()->validateInstances($spec,$rows,$raw);
    same(true,$valid->valid());
    $labels=[$key($text,$one)=>'Allowed label',$key($password,$one)=>'Private label'];
    $stored=StoredValues::instances($spec,$rows,$valid,9,'2026-09-27 12:00:00',$labels);
    same($rows,$stored['instances']); same('A',$stored['values'][$key($text,$one)]); same('B',$stored['values'][$key($text,$two)]);
    same(4,count($stored['values'])); same(false,str_contains(json_encode($stored),'secret')); same(false,str_contains(json_encode($stored),'temporary'));
    same([$key($text,$one)=>'Allowed label'],$stored['option_labels']);
    same(true,$stored['consents'][$key($consent,$one)]['accepted']); same(false,$stored['consents'][$key($consent,$two)]['accepted']);
    same('Terms',$stored['consents'][$key($consent,$one)]['text']); same(9,$stored['consents'][$key($consent,$one)]['form_version_id']);
    foreach(['metadata','none'] as $mode) {
        $data['persistence']['mode']=$mode;
        same(['values'=>[],'consents'=>[],'option_labels'=>[],'instances'=>[]],StoredValues::instances(new FormSpec($data),$rows,$valid,9,'now',$labels));
    }
    raises(InvalidArgumentException::class,fn()=>StoredValues::instances($spec,$rows,new ValidationResult($valid->values,[$group=>['error']],$valid->rules),9,'now'));
});

test('inactive repeated branches leave no stored row declarations or values', function (): void {
    [$group,$field,$row]=array_map(static fn()=>Uuid::create(),range(1,3));
    $spec=new FormSpec(['schema_version'=>'1.0','elements'=>[['uuid'=>$group,'type'=>'repeatable-group','visible'=>false,'repeat'=>['min'=>1,'max'=>2]],['uuid'=>$field,'type'=>'field','parent_uuid'=>$group]],'fields'=>[['uuid'=>$field,'type'=>'text']],'rules'=>[]]);
    $rows=[$group=>[$row]]; $key=(new FieldAddress($field,[['group'=>$group,'instance'=>$row]]))->key();
    $valid=validation()->validateInstances($spec,$rows,[$key=>'inactive secret']);
    same(['values'=>[],'consents'=>[],'option_labels'=>[],'instances'=>[]],StoredValues::instances($spec,$rows,$valid,1,'now'));
    raises(InvalidArgumentException::class,fn()=>StoredValues::instances($spec,$rows,new ValidationResult([$key=>'forged'],[],$valid->rules),1,'now'));
});
