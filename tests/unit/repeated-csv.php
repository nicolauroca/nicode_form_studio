<?php
declare(strict_types=1);

test('CSV field columns retain repeated scope order nested values and ordinary scalar types', function (): void {
    [$field,$ordinary,$group,$inner,$one,$two,$child] = array_map(static fn()=>Nicode\FormStudio\Domain\Uuid::create(),range(1,7));
    $pathOne=$group.'/'.$one.'/'.$inner.'/'.$child; $pathTwo=$group.'/'.$two.'/'.$inner.'/'.$child;
    $values=[$ordinary=>false,$pathTwo.'/'.$field=>['=SUM(A1)','ñ'], $pathOne.'/'.$field=>0];
    $columns=Nicode\FormStudio\Export\FieldColumns::project($values,[$field,$ordinary]);
    same([['instance_path'=>$pathTwo,'value'=>['=SUM(A1)','ñ']],['instance_path'=>$pathOne,'value'=>0]],$columns[$field]); same(false,$columns[$ordinary]);
    $stream=fopen('php://temp','w+b'); (new Nicode\FormStudio\Export\CsvWriter())->row($stream,array_values($columns)); rewind($stream); $row=fgetcsv($stream,escape:''); fclose($stream);
    same($columns[$field],json_decode($row[0],true,flags:JSON_THROW_ON_ERROR)); same('false',$row[1]);
    same([$field=>null],Nicode\FormStudio\Export\FieldColumns::project([],[$field]));
    raises(InvalidArgumentException::class,fn()=>Nicode\FormStudio\Export\FieldColumns::project($values+[$field=>'ambiguous'],[$field]));
});
