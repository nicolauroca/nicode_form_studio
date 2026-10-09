<?php
declare(strict_types=1);
use Nicode\FormStudio\Application\SubmissionColumns;
use Nicode\FormStudio\Domain\{FieldAddress, Uuid};

test('submission columns preserve nested row identity, order, null and historical option labels', function (): void {
    [$field,$ordinary,$absent,$outer,$inner,$parent,$one,$two] = array_map(static fn()=>Uuid::create(),range(1,8));
    $key = static fn($row)=>(new FieldAddress($field,[['group'=>$outer,'instance'=>$parent],['group'=>$inner,'instance'=>$row]]))->key();
    $answer = ['values'=>[$key($two)=>null,$ordinary=>['a','b'],$key($one)=>['c']], 'labels'=>[$key($two)=>'Historical',$key($one)=>'Historical'], 'option_labels'=>[$key($one)=>'Choice C',$ordinary=>'A, B']];
    $cells = SubmissionColumns::project($answer,[$field=>'Current',$ordinary=>'Multi',$absent=>'Absent']);
    same(true,$cells[$field]['present']); same('Historical',$cells[$field]['label']);
    same([
        ['instance_path'=>substr($key($two),0,-37),'value'=>null,'option_label'=>null],
        ['instance_path'=>substr($key($one),0,-37),'value'=>['c'],'option_label'=>'Choice C'],
    ],$cells[$field]['value']);
    same(['a','b'],$cells[$ordinary]['value']); same('A, B',$cells[$ordinary]['option_label']);
    same(false,$cells[$absent]['present']); same(null,$cells[$absent]['value']);
    same(true,SubmissionColumns::project(['values'=>[$ordinary=>null]],[$ordinary=>'Null'])[$ordinary]['present']);
    $answer['values'][$field]='invalid root scope';
    raises(InvalidArgumentException::class,fn()=>SubmissionColumns::project($answer,[$field=>'Current']));
});

test('submission columns mask historical repeated fields without exposing values or private labels', function (): void {
    [$field,$group,$row] = array_map(static fn()=>Uuid::create(),range(1,3));
    $key = (new FieldAddress($field,[['group'=>$group,'instance'=>$row]]))->key();
    $answer = ['masked'=>[$key], 'values'=>[$key=>'secret'], 'labels'=>[$key=>'Private title'], 'option_labels'=>[$key=>'Private choice']];
    same(['present'=>false,'value'=>null,'label'=>'Current public title','option_label'=>null,'masked'=>true],SubmissionColumns::project($answer,[$field=>'Current public title'])[$field]);
    same([],SubmissionColumns::project($answer,[]));
});
