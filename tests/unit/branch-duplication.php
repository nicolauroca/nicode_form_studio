<?php
declare(strict_types=1);

test('branch duplication remaps internal references and keeps external literals policies and originals', function (): void {
    $draft = withSecond(definition()); [$first,$second] = array_column($draft['fields'],'uuid');
    $group = Nicode\FormStudio\Domain\Uuid::create(); $outside = Nicode\FormStudio\Domain\Uuid::create();
    $draft['elements'][] = ['uuid'=>$group,'type'=>'group'];
    foreach ($draft['elements'] as &$element) { if ($element['type']==='field') $element['parent_uuid']=$group; } unset($element);
    $draft['elements'][] = ['uuid'=>$outside,'type'=>'field'];
    $draft['fields'][] = ['uuid'=>$outside,'type'=>'text','name'=>$draft['fields'][0]['name'].'_copy','config'=>[]];
    $draft['fields'][1]['prefill'] = ['type'=>'field','field'=>$first];
    $draft['fields'][1]['validators'] = [['type'=>'confirmation','config'=>['fields'=>[$first,$second]]]];
    $draft['fields'][0]['config']['default'] = $first;
    $draft['rules'] = [['uuid'=>Nicode\FormStudio\Domain\Uuid::create(),'when'=>['field'=>$outside,'operator'=>'equals','value'=>$first],'effects'=>[['type'=>'set_value','target'=>$second,'value'=>$first],['type'=>'clear_value','target'=>$outside]]]];
    $draft['actions'] = [['uuid'=>Nicode\FormStudio\Domain\Uuid::create(),'type'=>'fixture','config'=>[]]];
    $draft['post_submit'] = ['preserve'=>[$first]];
    $draft['translations'] = ['es-ES'=>['fields'=>[$first=>['label'=>'Primero'],$outside=>['label'=>'Externo']],'elements'=>[$group=>['title'=>'Grupo']]]];
    $original = $draft; $remapper = new Nicode\FormStudio\Domain\DefinitionRemapper();
    $result = $remapper->duplicateBranch($draft,$group); $copy = $result['definition']; $map = $result['identities'];
    same($original,$draft); same($original['elements'],array_slice($copy['elements'],0,count($original['elements'])));
    same($original['fields'],array_slice($copy['fields'],0,3));
    same($original['actions'],$copy['actions']); same($original['post_submit'],$copy['post_submit']);
    same($map[$group],$result['root']); same($map[$group],$copy['elements'][4]['parent_uuid']);
    same($map[$first],$copy['fields'][4]['prefill']['field']);
    same([$map[$first],$map[$second]],$copy['fields'][4]['validators'][0]['config']['fields']);
    same($first,$copy['fields'][3]['config']['default']);
    same($draft['fields'][0]['name'].'_copy_2',$copy['fields'][3]['name']);
    same(1,count($copy['rules'][1]['effects'])); same($map[$second],$copy['rules'][1]['effects'][0]['target']);
    same($first,$copy['rules'][1]['effects'][0]['value']); same($outside,$copy['rules'][1]['when']['field']);
    same($first,$copy['rules'][1]['when']['value']);
    same('Primero',$copy['translations']['es-ES']['fields'][$map[$first]]['label']);
    same('Grupo',$copy['translations']['es-ES']['elements'][$map[$group]]['title']);
    same('Externo',$copy['translations']['es-ES']['fields'][$outside]['label']);
    $again = $remapper->duplicateBranch($copy,$group)['definition'];
    same(count($again['fields']),count(array_unique(array_column($again['fields'],'name'))));
    raises(InvalidArgumentException::class,fn()=>$remapper->duplicateBranch($draft,Nicode\FormStudio\Domain\Uuid::create()));
});

test('duplicated repeated branches preserve limits and remap lexical prefill into independent rows', function (): void {
    $draft=withSecond(definition()); [$first,$second]=array_column($draft['fields'],'uuid');
    $outer=Nicode\FormStudio\Domain\Uuid::create(); $inner=Nicode\FormStudio\Domain\Uuid::create();
    $draft['elements']=[['uuid'=>$outer,'type'=>'repeatable-group','repeat'=>['min'=>1,'max'=>3]],['uuid'=>$inner,'type'=>'repeatable-group','parent_uuid'=>$outer,'repeat'=>['min'=>1,'max'=>2]],['uuid'=>$first,'type'=>'field','parent_uuid'=>$inner],['uuid'=>$second,'type'=>'field','parent_uuid'=>$inner]];
    $draft['fields'][0]['config']['default']='Original';
    $draft['fields'][1]['prefill']=['type'=>'field','field'=>$first];
    $result=(new Nicode\FormStudio\Domain\DefinitionRemapper())->duplicateBranch($draft,$outer);
    $copy=$result['definition']; $ids=$result['identities'];
    same(true,compiler()->compilePreview($copy)->successful());
    $elements=array_column($copy['elements'],null,'uuid');
    same(['min'=>1,'max'=>3],$elements[$ids[$outer]]['repeat']); same(['min'=>1,'max'=>2],$elements[$ids[$inner]]['repeat']);
    $fields=array_column($copy['fields'],null,'uuid'); same($ids[$first],$fields[$ids[$second]]['prefill']['field']);
    $instances=Nicode\FormStudio\Domain\RepeatedInstances::initial($copy['elements']);
    $addresses=$instances->addresses(); same(4,count($addresses));
    $copyFirst=array_values(array_filter($addresses,static fn($address)=>$address->field===$ids[$first]))[0];
    $copySecond=array_values(array_filter($addresses,static fn($address)=>$address->field===$ids[$second]))[0];
    same($copyFirst->key(),$instances->resolve($copySecond,$ids[$first])->key());
    raises(InvalidArgumentException::class,fn()=>$instances->resolve($copySecond,$first));
    same(true,compiler()->compile($copy)->successful());
});
