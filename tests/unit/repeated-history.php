<?php
declare(strict_types=1);

test('historical repeated layouts retain nested row order and isolate masked addresses', function (): void {
    [$outer,$inner,$field,$one,$two,$child] = array_map(static fn () => Nicode\FormStudio\Domain\Uuid::create(), range(1,6));
    $definition = ['fields'=>[['uuid'=>$field,'type'=>'text','name'=>'answer','config'=>['label'=>'Historical answer','private'=>'hidden']]],'elements'=>[
        ['uuid'=>$outer,'type'=>'repeatable-group','title'=>'Household','repeat'=>['min'=>0,'max'=>2]],
        ['uuid'=>$inner,'type'=>'repeatable-group','parent_uuid'=>$outer,'title'=>'Contact','repeat'=>['min'=>0,'max'=>2]],
        ['uuid'=>$field,'type'=>'field','parent_uuid'=>$inner],
    ]];
    $a=$outer.'/'.$one.'/'.$inner.'/'.$child.'/'.$field; $b=$outer.'/'.$two.'/'.$inner.'/'.$child.'/'.$field;
    $rows=[$outer=>[$two,$one],$outer.'/'.$one.'/'.$inner=>[$child],$outer.'/'.$two.'/'.$inner=>[$child]];
    $layout=Nicode\FormStudio\Application\SubmissionPresentation::layout($definition,[$a=>'one'],[$b],$rows);
    $first=$layout[0]['children'][0]['children'][0]['children'][0]['children'][0];
    $second=$layout[0]['children'][1]['children'][0]['children'][0]['children'][0];
    same($b,$first['uuid']); same(true,$first['masked']); same($a,$second['uuid']); same(false,$second['masked']);
    same('Historical answer',$second['label']); same(false,str_contains(json_encode($layout),'private'));
    same([],Nicode\FormStudio\Application\SubmissionPresentation::layout($definition,[],[],$rows));
    raises(InvalidArgumentException::class,fn()=>Nicode\FormStudio\Application\SubmissionPresentation::layout($definition,[$field=>'foreign'],[],$rows));
});
