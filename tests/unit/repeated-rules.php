<?php
declare(strict_types=1);
use Nicode\FormStudio\Domain\{Uuid, FormSpec, FieldAddress, RepeatedInstances};
use Nicode\FormStudio\Validation\AddressedFieldValidator;

test('repeated rules isolate row conditions inherited activation and derived values', function (): void {
    [$group,$trigger,$container,$answer,$copy,$outside,$one,$two] = array_map(static fn()=>Uuid::create(), range(1,8));
    $elements = [
        ['uuid'=>$outside,'type'=>'field'],
        ['uuid'=>$group,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>2]],
        ['uuid'=>$trigger,'type'=>'field','parent_uuid'=>$group],
        ['uuid'=>$container,'type'=>'fieldset','parent_uuid'=>$group],
        ['uuid'=>$answer,'type'=>'field','parent_uuid'=>$container],
        ['uuid'=>$copy,'type'=>'field','parent_uuid'=>$group],
    ];
    $fields = [
        ['uuid'=>$outside,'type'=>'text','config'=>[]],
        ['uuid'=>$trigger,'type'=>'text','config'=>[]],
        ['uuid'=>$answer,'type'=>'text','config'=>['required'=>true]],
        ['uuid'=>$copy,'type'=>'text','config'=>[],'prefill'=>['type'=>'field','field'=>$trigger]],
    ];
    $data = ['schema_version'=>'1.0','elements'=>$elements,'fields'=>$fields,'rules'=>[
        ['uuid'=>Uuid::create(),'when'=>['group'=>'AND','children'=>[
            ['field'=>$trigger,'operator'=>'equals','value'=>'hide'],
            ['field'=>$outside,'operator'=>'equals','value'=>'yes'],
        ]],'effects'=>[['target'=>$container,'type'=>'hide']]],
    ]];
    $rows = [$group=>[$one,$two]];
    $key = static fn($field,$row)=>(new FieldAddress($field,[['group'=>$group,'instance'=>$row]]))->key();
    $values = [$outside=>'yes',$key($trigger,$one)=>'hide',$key($trigger,$two)=>'show'];
    $defaults = [$key($copy,$one)=>true,$key($copy,$two)=>true];
    $result = rules()->evaluateInstances(new FormSpec($data),$rows,$values,[],$defaults);
    same(false,$result->states[$key($answer,$one)]['active']);
    same(true,$result->states[$key($answer,$two)]['active']);
    same('hide',$result->values[$key($copy,$one)]); same('show',$result->values[$key($copy,$two)]);
    $validation = (new AddressedFieldValidator(registry()))->validate($fields,new RepeatedInstances($elements,$rows),$result);
    same([$key($answer,$two)=>['required']],$validation->errors);
    $values[$outside] = 'no';
    $result = rules()->evaluateInstances(new FormSpec($data),$rows,$values,[],$defaults);
    same(true,$result->states[$key($answer,$one)]['active']);
    // Effects retain declared order, even when the same target appears twice.
    $data['rules'][0]['effects'] = [['target'=>$copy,'type'=>'set_value','value'=>'first'],['target'=>$copy,'type'=>'clear_value']];
    $values[$outside] = 'yes';
    $result = rules()->evaluateInstances(new FormSpec($data),$rows,$values,[],$defaults);
    same(null,$result->values[$key($copy,$one)]); same('show',$result->values[$key($copy,$two)]);
    raises(InvalidArgumentException::class,fn()=>rules()->evaluateInstances(new FormSpec($data),$rows,[$trigger=>'unscoped']));
    raises(InvalidArgumentException::class,fn()=>rules()->evaluateInstances(new FormSpec($data),$rows,$values,[],[$copy=>true]));
    $data['rules'][0]['effects'] = [['target'=>$outside,'type'=>'hide']];
    raises(InvalidArgumentException::class,fn()=>rules()->evaluateInstances(new FormSpec($data),$rows,$values));
});

test('repeated dependent sources retain provider UUID bindings and independent defaults', function (): void {
    [$group,$parent,$child,$one,$two] = array_map(static fn()=>Uuid::create(),range(1,5));
    $elements = [['uuid'=>$group,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>2]],['uuid'=>$parent,'type'=>'field','parent_uuid'=>$group],['uuid'=>$child,'type'=>'field','parent_uuid'=>$group]];
    $source = ['type'=>'static','dependencies'=>[$parent],'ttl'=>60,'config'=>['options'=>[
        ['value'=>'MAD','label'=>'Madrid','default'=>true,'when'=>[$parent=>'ES']],
        ['value'=>'LIS','label'=>'Lisbon','default'=>true,'when'=>[$parent=>'PT']],
    ]]];
    $fields = [['uuid'=>$parent,'type'=>'text'],['uuid'=>$child,'type'=>'select','source'=>$source,'prefill'=>['type'=>'source']]];
    $spec = new FormSpec(['schema_version'=>'1.0','elements'=>$elements,'fields'=>$fields,'rules'=>[]]);
    $sources = new Nicode\FormStudio\Registry\DataSourceRegistry(); $sources->register(new Nicode\FormStudio\DataSource\StaticDataSource());
    $engine = new Nicode\FormStudio\Rules\RuleEngine(new Nicode\FormStudio\Rules\ConditionEvaluator(Nicode\FormStudio\Registry\RuleOperatorRegistry::core()),Nicode\FormStudio\Registry\RuleEffectRegistry::core(),registry(),64,new Nicode\FormStudio\DataSource\OptionResolver($sources,new Nicode\FormStudio\DataSource\RequestCache()));
    $key = static fn($field,$row)=>(new FieldAddress($field,[['group'=>$group,'instance'=>$row]]))->key();
    $result = $engine->evaluateInstances($spec,[$group=>[$one,$two]],[$key($parent,$one)=>'ES',$key($parent,$two)=>'PT'],[],[$key($child,$one)=>true,$key($child,$two)=>true]);
    same('MAD',$result->values[$key($child,$one)]); same('LIS',$result->values[$key($child,$two)]);
    same(['MAD'],array_column($result->states[$key($child,$one)]['options'],'value'));
    same(['LIS'],array_column($result->states[$key($child,$two)]['options'],'value'));
    same([], $engine->evaluateInstances($spec,[$group=>[]],[])->values);
});

test('nested repeated rules preserve parent identity with reused child row IDs and bound expansion', function (): void {
    [$outer,$inner,$trigger,$copy,$one,$two,$child] = array_map(static fn()=>Uuid::create(),range(1,7));
    $elements = [
        ['uuid'=>$outer,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>2]],
        ['uuid'=>$trigger,'type'=>'field','parent_uuid'=>$outer],
        ['uuid'=>$inner,'type'=>'repeatable-group','parent_uuid'=>$outer,'repeat'=>['min'=>0,'max'=>1]],
        ['uuid'=>$copy,'type'=>'field','parent_uuid'=>$inner],
    ];
    $scope = static fn($row)=>[['group'=>$outer,'instance'=>$row]];
    $key = static fn($id,$row)=>(new FieldAddress($id,$scope($row)))->key();
    $nested = static fn($row)=>(new FieldAddress($copy,[...$scope($row),['group'=>$inner,'instance'=>$child]]))->key();
    $rows = [$outer=>[$one,$two],$key($inner,$one)=>[$child],$key($inner,$two)=>[$child]];
    $fields = [['uuid'=>$trigger,'type'=>'text'],['uuid'=>$copy,'type'=>'text','prefill'=>['type'=>'field','field'=>$trigger]]];
    $data = ['schema_version'=>'1.0','elements'=>$elements,'fields'=>$fields,'rules'=>[
        ['uuid'=>Uuid::create(),'when'=>['field'=>$trigger,'operator'=>'equals','value'=>'hide'],'effects'=>[['target'=>$inner,'type'=>'hide']]],
    ]];
    $values = [$key($trigger,$one)=>'hide',$key($trigger,$two)=>'visible'];
    $result = rules()->evaluateInstances(new FormSpec($data),$rows,$values,[],[$nested($one)=>true,$nested($two)=>true]);
    same(false,$result->states[$nested($one)]['active']);
    same(false,array_key_exists($nested($one),$result->values));
    same('visible',$result->values[$nested($two)]);
    same(false,$result->states[$key($inner,$one)]['active']);
    same(true,$result->states[$key($inner,$two)]['active']);
    // Graph size fits, but the expanded effect count exceeds its separate budget.
    $data['rules'][0]['effects'] = array_fill(0,11,['target'=>$copy,'type'=>'hide']);
    raises(InvalidArgumentException::class,fn()=>rules()->evaluateInstances(new FormSpec($data),$rows,$values,[],[],20));
});
