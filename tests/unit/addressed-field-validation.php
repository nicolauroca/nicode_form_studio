<?php
 declare(strict_types=1);
 use Nicode\FormStudio\Domain\{Uuid,RepeatedInstances,FieldAddress};
 use Nicode\FormStudio\Rules\RuleResult;
 use Nicode\FormStudio\Validation\AddressedFieldValidator;
 test('addressed field validation shares type option required and index checks without crossing rows',function():void{
     [$group,$number,$choices,$text,$one,$two]=array_map(static fn()=>Uuid::create(),range(1,6));
     $fields=[['uuid'=>$number,'type'=>'integer','config'=>['min'=>1,'max'=>10]],['uuid'=>$choices,'type'=>'multiselect','config'=>[]],['uuid'=>$text,'type'=>'text','index'=>true,'config'=>[]]];
     $elements=[['uuid'=>$group,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>2]]];
     foreach($fields as $field) $elements[]=['uuid'=>$field['uuid'],'type'=>'field','parent_uuid'=>$group];
     $instances=new RepeatedInstances($elements,[$group=>[$one,$two]]);
     $key=static fn($field,$row)=>(new FieldAddress($field,[['group'=>$group,'instance'=>$row]]))->key();
     $states=[]; foreach($instances->addresses() as $address) $states[$address->key()]=['active'=>true,'required'=>$address->field===$number,'options'=>[['value'=>'x','enabled'=>true],['value'=>'y','enabled'=>false]]];
     $values=[$key($number,$one)=>' 7 ',$key($number,$two)=>'bad',$key($choices,$one)=>['x','x'],$key($choices,$two)=>['y'],$key($text,$one)=>' normal ',$key($text,$two)=>str_repeat('x',256)];
     $validator=new AddressedFieldValidator(registry());
     $result=$validator->validate($fields,$instances,new RuleResult($states,$values,1));
     same(7,$result->values[$key($number,$one)]); same(['x'],$result->values[$key($choices,$one)]); same('normal',$result->values[$key($text,$one)]);
     same([$key($number,$two)=>['type'],$key($choices,$two)=>['option'],$key($text,$two)=>['index_length']],$result->errors);
     unset($values[$key($number,$one)]); $values[$key($number,$two)]='11';
     $states[$key($choices,$two)]['active']=false;
     $result=$validator->validate($fields,$instances,new RuleResult($states,$values,1));
     same(['required'],$result->errors[$key($number,$one)]); same(['max'],$result->errors[$key($number,$two)]);
     same(false,isset($result->errors[$key($choices,$two)])); same(false,array_key_exists($key($choices,$two),$result->values));
     $states[$key($text,$one)]['required']=false;
     $result=$validator->validate($fields,$instances,new RuleResult($states,$values,1,[],[$key($text,$one)=>['type']]));
     same(['type'],$result->errors[$key($text,$one)]);
     unset($states[$key($text,$one)]);
     raises(InvalidArgumentException::class,fn()=>$validator->validate($fields,$instances,new RuleResult($states,$values,1)));
 });
