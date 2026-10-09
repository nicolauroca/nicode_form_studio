<?php
declare(strict_types=1);
use Nicode\FormStudio\Domain\{Uuid, FormSpec, FieldAddress, CanonicalJson};
use Nicode\FormStudio\Submission\RequestFingerprint;
use Nicode\FormStudio\Storage\StoredFile;

test('repeated fingerprints bind row order action-only values and stable file content', function (): void {
    [$group,$text,$file,$one,$two]=array_map(static fn()=>Uuid::create(),range(1,5));
    $data=['schema_version'=>'1.0','elements'=>[['uuid'=>$group,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>2]],['uuid'=>$text,'type'=>'field','parent_uuid'=>$group],['uuid'=>$file,'type'=>'field','parent_uuid'=>$group]],'fields'=>[
        ['uuid'=>$text,'type'=>'text','persist'=>false],
        ['uuid'=>$file,'type'=>'file','config'=>['extensions'=>['txt'],'mime_types'=>['text/plain'],'max_bytes'=>100]],
    ],'rules'=>[]];
    $spec=new FormSpec($data); $rows=[$group=>[$one,$two]];
    $key=static fn($id,$row)=>(new FieldAddress($id,[['group'=>$group,'instance'=>$row]]))->key();
    $raw=[$key($text,$one)=>'A',$key($text,$two)=>'B'];
    $receipt=['uuid'=>Uuid::create(),'name'=>'a.txt','mime'=>'text/plain','size'=>3];
    $valid=validation()->validateInstances($spec,$rows,$raw,[$key($file,$one)=>$receipt]);
    same(true,$valid->valid());
    $entry=['field_address'=>$key($file,$one),'receipt'=>$receipt,'file'=>new StoredFile('private','first-storage-key',3,str_repeat('a',64))];
    $context=['files'=>[$entry]]; $fingerprints=new RequestFingerprint(str_repeat('k',32));
    $first=$fingerprints->instances(5,$spec,$rows,$valid,$context);
    same($first,$fingerprints->instances(5,$spec,$rows,$valid,$context));
    $newReceipt=array_replace($receipt,['uuid'=>Uuid::create()]);
    $retry=validation()->validateInstances($spec,$rows,$raw,[$key($file,$one)=>$newReceipt]);
    $retryContext=['files'=>[array_replace($entry,['receipt'=>$newReceipt,'file'=>new StoredFile('private','different-key',3,str_repeat('a',64))])]];
    same($first,$fingerprints->instances(5,$spec,$rows,$retry,$retryContext));
    same(false,$first===$fingerprints->instances(5,$spec,[$group=>[$two,$one]],$valid,$context));
    $changed=validation()->validateInstances($spec,$rows,array_replace($raw,[$key($text,$one)=>'changed']),[$key($file,$one)=>$receipt]);
    same(false,$first===$fingerprints->instances(5,$spec,$rows,$changed,$context));
    foreach([['channel'=>'module'],['locale'=>'es-ES']] as $change) same(false,$first===$fingerprints->instances(5,$spec,$rows,$valid,array_replace($context,$change)));
    $different=['files'=>[array_replace($entry,['file'=>new StoredFile('private','first-storage-key',3,str_repeat('b',64))])]];
    same(false,$first===$fingerprints->instances(5,$spec,$rows,$valid,$different));
    raises(InvalidArgumentException::class,fn()=>$fingerprints->instances(5,$spec,$rows,$valid));
    raises(InvalidArgumentException::class,fn()=>$fingerprints->instances(5,$spec,$rows,$valid,['files'=>[$entry,$entry]]));
    $foreign=['files'=>[array_replace($entry,['field_address'=>$key($file,Uuid::create())])]];
    raises(InvalidArgumentException::class,fn()=>$fingerprints->instances(5,$spec,$rows,$valid,$foreign));
    // Ordinary fingerprints remain byte-compatible with already stored attempt hashes.
    $ordinary=[$text=>'A','unknown'=>'ignored'];
    same(hash_hmac('sha256',CanonicalJson::encode([5,[$text=>'A'],'component','en-GB']),str_repeat('k',32)),$fingerprints->ordinary(5,$spec,$ordinary));
});
