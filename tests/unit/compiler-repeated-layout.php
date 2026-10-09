<?php
declare(strict_types=1);
use Nicode\FormStudio\Compiler\RepeatedLayoutValidator;
use Nicode\FormStudio\Domain\Uuid;

function repeatedCompilerFixture(): array {
    $draft=definition(); [$group,$nested,$sibling,$root,$child,$deep,$other]=array_map(static fn()=>Uuid::create(),range(1,7));
    $draft['elements']=[['uuid'=>$group,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>2]],['uuid'=>$nested,'type'=>'repeatable-group','parent_uuid'=>$group,'repeat'=>['min'=>0,'max'=>2]],['uuid'=>$sibling,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>2]]];
    $draft['fields']=[];
    foreach([[$root,null],[$child,$group],[$deep,$nested],[$other,$sibling]] as [$id,$parent]) { $draft['elements'][]=['uuid'=>$id,'type'=>'field','parent_uuid'=>$parent]; $draft['fields'][]=['uuid'=>$id,'name'=>'field_'.count($draft['fields']),'type'=>'text','config'=>[]]; }
    return [$draft,compact('group','nested','sibling','root','child','deep','other')];
}

test('compiler and repeated runtime bound aggregate condition trees at the same exact limit',function():void {
    [$group,$field,$one,$two]=array_map(static fn()=>Uuid::create(),range(1,4));
    $draft=definition();
    $draft['elements']=[['uuid'=>$group,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>2]],['uuid'=>$field,'type'=>'field','parent_uuid'=>$group]];
    $draft['fields']=[['uuid'=>$field,'name'=>'answer','type'=>'text','config'=>[]]];
    $leaf=['field'=>$field,'operator'=>'equals','value'=>'hide'];
    $draft['rules']=array_map(static fn()=>['uuid'=>Uuid::create(),'when'=>['group'=>'AND','children'=>[$leaf,$leaf]],'effects'=>[['type'=>'hide','target'=>$field]]],range(1,2));
    $validator=new RepeatedLayoutValidator();
    same([],$validator->validate($draft,12));
    $errors=$validator->validate($draft,11);
    same(['rule.repeatable.condition_budget'],array_column($errors,'code')); same('/rules/1/when',$errors[0]->path);
    $rows=[$group=>[$one,$two]]; $values=[$group.'/'.$one.'/'.$field=>'keep',$group.'/'.$two.'/'.$field=>'keep'];
    $spec=new Nicode\FormStudio\Domain\FormSpec($draft);
    same($values,rules()->evaluateInstances($spec,$rows,$values,[],[],12)->values);
    try { rules()->evaluateInstances($spec,$rows,$values,[],[],11); throw new RuntimeException('Expanded conditions exceeded the shared budget.'); }
    catch(InvalidArgumentException $error) { same('Expanded rule condition budget exceeded.',$error->getMessage()); }
    // Empty initial rows do not conceal the configured worst case from the compiler.
    same([],rules()->evaluateInstances($spec,[$group=>[]],[],[],[],11)->values);
    // Multiple effects copy the condition just as multiple rules do.
    $draft['rules'][0]['effects'][]=['type'=>'disable','target'=>$field]; array_pop($draft['rules']);
    same([],$validator->validate($draft,12));
    same('rule.repeatable.condition_budget',$validator->validate($draft,11)[0]->code);
});

test('compiler combines field and form validator calls at the same boundary as repeated validation',function():void {
    [$group,$field,$one,$two]=array_map(static fn()=>Uuid::create(),range(1,4));
    $check=['type'=>'at_least_one','config'=>['fields'=>[$field]]];
    $draft=definition();
    $draft['elements']=[['uuid'=>$group,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>2]],['uuid'=>$field,'type'=>'field','parent_uuid'=>$group]];
    $draft['fields']=[['uuid'=>$field,'type'=>'text','name'=>'answer','config'=>[],'validators'=>[$check,$check]]];
    $draft['validators']=[$check];
    $rows=[$group=>[$one,$two]]; $values=[$group.'/'.$one.'/'.$field=>'A',$group.'/'.$two.'/'.$field=>'B'];
    $compiler=new RepeatedLayoutValidator();
    $engine=new Nicode\FormStudio\Validation\ValidationEngine(registry(),rules(),Nicode\FormStudio\Registry\ValidatorRegistry::core());
    same([],$compiler->validate($draft,6));
    same([],$engine->validateInstances(new Nicode\FormStudio\Domain\FormSpec($draft),$rows,$values,budget:6)->errors);
    $draft['validators'][]=$check;
    $errors=$compiler->validate($draft,6);
    same(['validator.repeatable.budget'],array_column($errors,'code')); same('/validators/1',$errors[0]->path);
    raises(InvalidArgumentException::class,fn()=>$engine->validateInstances(new Nicode\FormStudio\Domain\FormSpec($draft),$rows,$values,budget:6));
    same([],$compiler->validate($draft,8));
    same([],$engine->validateInstances(new Nicode\FormStudio\Domain\FormSpec($draft),$rows,$values,budget:8)->errors);
});

test('repeated compiler validates limits and maximum expansion without allocating row identities',function():void {
    [$draft,$ids]=repeatedCompilerFixture(); $validator=new RepeatedLayoutValidator();
    same([],$validator->validate($draft));
    same([],$validator->validate($draft,13));
    same(['layout.repeatable.budget'],array_column($validator->validate($draft,12),'code'));
    foreach([null,[],['min'=>'0','max'=>2],['min'=>-1,'max'=>2],['min'=>3,'max'=>2],['min'=>0,'max'=>10001],['min'=>0,'max'=>2,'extra'=>true]] as $limits) {
        $bad=$draft; $bad['elements'][0]['repeat']=$limits; $errors=$validator->validate($bad);
        same(['layout.repeatable.limits'],array_column($errors,'code')); same('/elements/0/repeat',$errors[0]->path);
    }
    $huge=$draft; $huge['elements'][0]['repeat']['max']=100; $huge['elements'][1]['repeat']['max']=100;
    same(['layout.repeatable.budget'],array_column($validator->validate($huge),'code'));
    // Initial minima are zero; maximum potential still has to fit the shared budget.
    same(true,in_array('layout.repeatable.budget',array_column(compiler()->compile($huge)->diagnostics,'code'),true));
    $published=compiler()->compile($draft); same(true,$published->successful());
    same([], $published->diagnostics);
    same(true,compiler()->compilePreview($draft)->successful());
    same(false,compiler()->compilePreview($huge)->successful());
    $badPreview=$draft; $badPreview['fields'][0]['prefill']=['type'=>'field','field'=>$ids['deep']];
    same(true,in_array('reference.repeatable.scope',array_column(compiler()->compilePreview($badPreview)->diagnostics,'code'),true));
});

test('compiler prevents a form-wide CAPTCHA from multiplying at a repeated maximum',function():void {
    [$draft,$id]=repeatedCompilerFixture(); $captcha=Uuid::create();
    $draft['elements'][]=['uuid'=>$captcha,'type'=>'captcha','parent_uuid'=>$id['nested']];
    $validator=new RepeatedLayoutValidator(); $errors=$validator->validate($draft);
    same(['layout.repeatable.captcha'],array_column($errors,'code')); same('/elements/7',$errors[0]->path);
    $draft['elements'][7]['parent_uuid']=null; same([],$validator->validate($draft));
    $draft['elements'][7]['parent_uuid']=$id['nested'];
    $draft['elements'][0]['repeat']['max']=1; $draft['elements'][1]['repeat']['max']=1;
    same([],$validator->validate($draft));
});

test('repeated compiler permits lexical ancestors but rejects siblings and descendants even with zero minimum rows',function():void {
    [$draft,$id]=repeatedCompilerFixture(); $validator=new RepeatedLayoutValidator();
    $draft['fields'][2]['prefill']=['type'=>'field','field'=>$id['child']];
    $draft['fields'][2]['source']=['type'=>'fixture','dependencies'=>[$id['root'],$id['child']]];
    $draft['fields'][2]['validators']=[['type'=>'confirmation','config'=>['fields'=>[$id['deep'],$id['child']]]]];
    same([],$validator->validate($draft));
    foreach([
        ['/fields/2/prefill/field',static function(&$d)use($id){$d['fields'][2]['prefill']['field']=$id['other'];}],
        ['/fields/2/source/dependencies/0',static function(&$d)use($id){$d['fields'][2]['source']['dependencies'][0]=$id['other'];}],
        ['/fields/2/validators/0/config/fields/1',static function(&$d)use($id){$d['fields'][2]['validators'][0]['config']['fields'][1]=$id['other'];}],
        ['/fields/0/prefill/field',static function(&$d)use($id){$d['fields'][0]['prefill']=['type'=>'field','field'=>$id['deep']];}],
    ] as [$path,$mutate]) {
        $bad=$draft; $mutate($bad); $errors=$validator->validate($bad);
        same(['reference.repeatable.scope'],array_column($errors,'code')); same($path,$errors[0]->path);
    }
    [$bad,$id]=repeatedCompilerFixture();
    $bad['fields'][0]['prefill']=['type'=>'field','field'=>$id['deep']];
    $compiled=compiler()->compile($bad);
    $scoped=array_values(array_filter($compiled->diagnostics,static fn($error)=>$error->code==='reference.repeatable.scope'));
    same(1,count($scoped)); same('/fields/0/prefill/field',$scoped[0]->path);
});

test('repeated rules use target scope while form conditions and validators require comparable rows',function():void {
    [$draft,$id]=repeatedCompilerFixture(); $validator=new RepeatedLayoutValidator();
    $condition=['group'=>'AND','children'=>[['field'=>$id['root'],'operator'=>'equals','value'=>'x'],['field'=>$id['child'],'operator'=>'equals','value'=>'y']]];
    $draft['rules']=[['uuid'=>Uuid::create(),'when'=>$condition,'effects'=>[['type'=>'hide','target'=>$id['deep']]]]];
    $draft['validators']=[['type'=>'confirmation','config'=>['fields'=>[$id['child'],$id['deep']]]]];
    $draft['actions']=[['type'=>'fixture','condition'=>$condition,'config'=>[]]];
    $draft['post_submit']['conditional_messages']=[['condition'=>$condition,'message'=>'Matched']];
    same([],$validator->validate($draft));
    $bad=$draft; $bad['rules'][0]['effects'][0]['target']=$id['group'];
    same('/rules/0/effects/0/target',$validator->validate($bad)[0]->path);
    foreach(['validators','actions','post'] as $kind) {
        $bad=$draft;
        if($kind==='validators') {$bad['validators'][0]['config']['fields'][1]=$id['other'];}
        elseif($kind==='actions') {$bad['actions'][0]['condition']['children'][0]['field']=$id['other'];}
        else {$bad['post_submit']['conditional_messages'][0]['condition']['children'][0]['field']=$id['other'];}
        same(['reference.repeatable.scope'],array_column($validator->validate($bad),'code'));
    }
    $bad=$draft; $bad['actions'][0]['config']['email_field']=$id['child'];
    same(['action.repeatable.recipient'],array_column($validator->validate($bad),'code'));
    foreach (Nicode\FormStudio\Actions\EmailAction::ROW_SELECTIONS as $selection) {
        $bad['actions'][0]['config']['email_field_selection']=$selection; same([],$validator->validate($bad));
    }
    $bad=$draft; $bad['rules'][0]['effects']=array_fill(0,4,['type'=>'hide','target'=>$id['deep']]);
    same(true,in_array('rule.repeatable.budget',array_column($validator->validate($bad,15),'code'),true));
    $bad=$draft; $bad['actions'][0]['condition']=['group'=>'AND','children'=>array_fill(0,8,['field'=>$id['child'],'operator'=>'equals','value'=>'x'])];
    same(true,in_array('reference.repeatable.budget',array_column($validator->validate($bad,15),'code'),true));
});
