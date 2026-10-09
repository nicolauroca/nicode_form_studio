<?php
declare(strict_types=1);
use Nicode\FormStudio\Domain\{Uuid, FormSpec, FieldAddress};
use Nicode\FormStudio\Rules\PresentationState;

test('repeated presentation initializes each row and preserves retry authority without restoring cleared answers', function (): void {
    [$group,$editable,$copy,$password,$query,$one,$two] = array_map(static fn()=>Uuid::create(),range(1,7));
    $fields = [
        ['uuid'=>$editable,'type'=>'text','config'=>['default'=>' initial ']],
        ['uuid'=>$copy,'type'=>'text','config'=>['readonly'=>true],'prefill'=>['type'=>'field','field'=>$editable]],
        ['uuid'=>$password,'type'=>'password','config'=>[]],
        ['uuid'=>$query,'type'=>'integer','config'=>['min'=>1,'max'=>10],'prefill'=>['type'=>'query','key'=>'number']],
    ];
    $elements = [['uuid'=>$group,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>2]]];
    foreach ($fields as $field) { $elements[] = ['uuid'=>$field['uuid'],'type'=>'field','parent_uuid'=>$group]; }
    $spec = new FormSpec(['schema_version'=>'1.0','elements'=>$elements,'fields'=>$fields,'rules'=>[]]);
    $key = static fn($id,$row)=>(new FieldAddress($id,[['group'=>$group,'instance'=>$row]]))->key();
    $rows = [$group=>[$one,$two]]; $state = new PresentationState(registry(),rules());
    $initial = $state->evaluateInstances($spec,$rows,['prefill_query'=>['number'=>'11']],[$key($editable,$one)=>' trusted ',$key($password,$one)=>'private']);
    same('trusted',$initial->values[$key($editable,$one)]); same('trusted',$initial->values[$key($copy,$one)]);
    same('initial',$initial->values[$key($copy,$two)]); same(null,$initial->values[$key($query,$one)]);
    same(null,$initial->values[$key($password,$one)]);
    $submitted = [$key($editable,$one)=>' changed ',$key($copy,$one)=>'forged',$key($password,$one)=>'secret',$key($query,$two)=>['bad']];
    $retry = $state->evaluateInstances($spec,$rows,['prefill_query'=>['number'=>'3']],[],$submitted);
    same('changed',$retry->values[$key($copy,$one)]); same(null,$retry->values[$key($editable,$two)]);
    same(null,$retry->values[$key($copy,$two)]); same(null,$retry->values[$key($password,$one)]);
    $reset = $state->evaluateInstances($spec,$rows,[],[],[$key($editable,$one)=>' kept '],reset:true);
    same('kept',$reset->values[$key($editable,$one)]); same('initial',$reset->values[$key($editable,$two)]);
    same('kept',$reset->values[$key($copy,$one)]); same('initial',$reset->values[$key($copy,$two)]);
    $cleared = $state->evaluateInstances($spec,$rows,[],[],[$key($editable,$one)=>null],reset:true);
    same(null,$cleared->values[$key($editable,$one)]); same('initial',$cleared->values[$key($editable,$two)]);
    $ordinary = new FormSpec(['schema_version'=>'1.0','elements'=>[['uuid'=>$editable,'type'=>'field']],'fields'=>[$fields[0]],'rules'=>[]]);
    same('initial',$state->evaluate($ordinary,submitted:[],reset:true)->values[$editable]);
    same(null,$state->evaluate($ordinary,submitted:[])->values[$editable]);
    same(null,$state->evaluate($ordinary,submitted:[$editable=>null],reset:true)->values[$editable]);
    same(null,$retry->values[$key($query,$two)]);
    $pinned = $state->evaluateInstances($spec,$rows,[],[$key($copy,$one)=>null],$submitted);
    same(null,$pinned->values[$key($copy,$one)]);
    raises(InvalidArgumentException::class,fn()=>$state->evaluateInstances($spec,$rows,[],[],[$editable=>'unscoped']));
    same([],$state->evaluateInstances($spec,[$group=>[]])->values);
});
