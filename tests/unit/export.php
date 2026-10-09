<?php
declare(strict_types=1);

test('CSV export neutralizes formulas, quotes multiline data and keeps structured values literal', function (): void {
    $csv = new Nicode\FormStudio\Export\CsvWriter();
    foreach (['=1+1', '+SUM(A1:A2)', '-2+1', '@SUM(A1)', "\t=1", ' =1', "\xEF\xBB\xBF=1"] as $value) { same("'" . $value, $csv->cell($value)); }
    same('safe', $csv->cell('safe')); same('["a","b"]', $csv->cell(['a', 'b']));
    $stream = fopen('php://temp', 'w+b'); $csv->row($stream, ['a,b', "line\nnext", '"quoted"', '=1+1']); rewind($stream);
    same(['a,b', "line\nnext", '"quoted"', "'=1+1"], fgetcsv($stream, escape: '')); fclose($stream);
});
test('export workspace retry discards only bytes beyond durable checkpoint', function (): void {
    $base = __DIR__ . '/../artifacts'; $private = $base . '/export-private'; $public = $base . '/export-public';
    foreach ([$private, $public] as $directory) { if (!is_dir($directory)) { mkdir($directory, 0770, true); } }
    $workspace = new Nicode\FormStudio\Export\ExportWorkspace($private, $public); $uuid = Nicode\FormStudio\Domain\Uuid::create(); $checks = 0;
    $lease = static function () use (&$checks): void { $checks++; };
    $bytes = $workspace->append($uuid, 0, ['header'], [['first']], $lease);
    $workspace->append($uuid, $bytes, ['header'], [['uncheckpointed']], $lease);
    $workspace->append($uuid, $bytes, ['header'], [['replacement']], $lease);
    $stream = $workspace->open($uuid); $content = stream_get_contents($stream); fclose($stream);
    same("header\r\nfirst\r\nreplacement\r\n", $content); same(3, $checks);
    $workspace->delete($uuid); raises(InvalidArgumentException::class, fn () => $workspace->open('../private'));
});

test('JSON export streams typed records with exact checkpoint recovery including final and empty chunks', function (): void {
    $base=__DIR__.'/../artifacts'; $private=$base.'/export-private'; $public=$base.'/export-public';
    foreach ([$private,$public] as $directory) { if (!is_dir($directory)) mkdir($directory,0770,true); }
    $workspace=new Nicode\FormStudio\Export\ExportWorkspace($private,$public); $uuid=Nicode\FormStudio\Domain\Uuid::create();
    $lease=static function():void {}; $read=static function()use($workspace,$uuid):string { $stream=$workspace->open($uuid,'json'); try { return stream_get_contents($stream); } finally { fclose($stream); } };
    $first=['reference'=>'one','values'=>['null'=>null,'boolean'=>false,'integer'=>3,'decimal'=>1.0,'list'=>['á',"line\nnext"],'literal'=>'=1+1']];
    $second=['reference'=>'two','values'=>['nested'=>[['instance_path'=>'group/row','value'=>'"quoted"']]]];
    try {
        same(1,$workspace->appendJson($uuid,0,[],false,$lease));
        $checkpoint=$workspace->appendJson($uuid,1,[$first],false,$lease);
        $workspace->appendJson($uuid,$checkpoint,[['reference'=>'unconfirmed']],true,$lease);
        $bytes=$workspace->appendJson($uuid,$checkpoint,[$second],true,$lease);
        same(Nicode\FormStudio\Domain\CanonicalJson::encode([$first,$second]),$read());
        same($bytes,$workspace->appendJson($uuid,$checkpoint,[$second],true,$lease));
        same(Nicode\FormStudio\Domain\CanonicalJson::encode([$first,$second]),$read());
        raises(RuntimeException::class,fn()=>$workspace->appendJson($uuid,$bytes+1,[],true,$lease));
        $before=$read();
        raises(DomainException::class,fn()=>$workspace->appendJson($uuid,0,[],true,static fn()=>throw new DomainException('expired lease')));
        same($before,$read());
        // Failed serialization leaves only uncheckpointed data, which retry discards.
        raises(JsonException::class,fn()=>$workspace->appendJson($uuid,$checkpoint,[['reference'=>'partial'],['invalid'=>"\xFF"]],true,$lease));
        $workspace->appendJson($uuid,$checkpoint,[],true,$lease);
        same(Nicode\FormStudio\Domain\CanonicalJson::encode([$first]),$read());
        same(2,$workspace->appendJson($uuid,0,[],true,$lease)); same('[]',$read());
        raises(InvalidArgumentException::class,fn()=>$workspace->open($uuid,'../json'));
        raises(InvalidArgumentException::class,fn()=>$workspace->appendJson($uuid,0,[[1,2]],true,$lease));
        $workspace->append($uuid,0,['csv'],[['separate']],$lease);
        $workspace->delete($uuid,'json');
        $stream=$workspace->open($uuid); same("csv\r\nseparate\r\n",stream_get_contents($stream)); fclose($stream);
    } finally { $workspace->delete($uuid,'json'); $workspace->delete($uuid); }
});
