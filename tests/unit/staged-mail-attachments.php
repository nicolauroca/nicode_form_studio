<?php
declare(strict_types=1);

test('request-local attachments enforce snapshot ownership integrity policy and repeated order', function (): void {
    $storage=new Nicode\FormStudio\Storage\LocalStorage(dirname(__DIR__).'/artifacts',dirname(__DIR__,2).'/src');
    $registry=new Nicode\FormStudio\Registry\StorageProviderRegistry(); $registry->register($storage);
    $draft=definition(); $field=$draft['fields'][0]['uuid']; $draft['fields'][0]['type']='multiple-files';
    $draft['fields'][0]['sensitive']=true; $draft['fields'][0]['include_email']=true;
    $reference=Nicode\FormStudio\Domain\Uuid::create(); $objects=[];
    $make=static function(string $bytes,string $address)use($storage,&$objects):array {
        $stream=fopen('php://temp','w+b'); fwrite($stream,$bytes); rewind($stream);
        try { $object=$storage->put($stream,100); } finally { fclose($stream); }
        $objects[]=$object;
        return ['field_address'=>$address,'receipt'=>['uuid'=>Nicode\FormStudio\Domain\Uuid::create(),'name'=>'evidence.txt','mime'=>'text/plain','size'=>strlen($bytes)],'file'=>$object];
    };
    $reject=static function(callable $call):void {
        try { $call(); throw new RuntimeException('Expected attachment rejection.'); }
        catch(Nicode\FormStudio\Actions\ActionFailure $failure) { same('mail_attachment_unavailable',$failure->resultCode); same(false,$failure->unknownOutcome); }
    };
    try {
        foreach(['full','metadata','none'] as $mode) {
            $draft['persistence']['mode']=$mode; $draft['fields'][0]['persist']=false;
            $spec=new Nicode\FormStudio\Domain\FormSpec($draft);
            $entries=[$make('first',$field),$make('second',$field)]; $values=[$field=>array_column($entries,'receipt')];
            // Persistence canonicalizes object key order; identity must preserve types, not key insertion order.
            $values=json_decode(Nicode\FormStudio\Domain\CanonicalJson::encode($values),true,512,JSON_THROW_ON_ERROR);
            $resolver=new Nicode\FormStudio\Application\StagedMailAttachments($registry,$spec,$reference,$values,null,array_reverse($entries));
            $context=new Nicode\FormStudio\Actions\ActionContext($spec,[],$reference,'date',attachments:$resolver);
            same(['first','second'],array_column($resolver->resolve([$field],$context),'bytes'));
            same($resolver,$context->forAction(Nicode\FormStudio\Domain\Uuid::create())->attachments);
            $reject(fn()=>$resolver->resolve([$field],new Nicode\FormStudio\Actions\ActionContext($spec,[],Nicode\FormStudio\Domain\Uuid::create(),'date')));
            $other=$draft; $other['fields'][0]['include_email']=false;
            $reject(fn()=>$resolver->resolve([$field],new Nicode\FormStudio\Actions\ActionContext(new Nicode\FormStudio\Domain\FormSpec($other),[],$reference,'date')));
            foreach([[],[$entries[0]],[$entries[0],$entries[0]]] as $bad) {
                $broken=new Nicode\FormStudio\Application\StagedMailAttachments($registry,$spec,$reference,$values,null,$bad);
                $reject(fn()=>$broken->resolve([$field],$context));
            }
            $bad=$entries; $bad[0]['field_address']=Nicode\FormStudio\Domain\Uuid::create();
            $broken=new Nicode\FormStudio\Application\StagedMailAttachments($registry,$spec,$reference,$values,null,$bad);
            $reject(fn()=>$broken->resolve([$field],$context));
            $bad=$entries; $object=$bad[0]['file'];
            $bad[0]['file']=new Nicode\FormStudio\Storage\StoredFile($object->provider,$object->key,$object->size,str_repeat('0',64));
            $broken=new Nicode\FormStudio\Application\StagedMailAttachments($registry,$spec,$reference,$values,null,$bad);
            $reject(fn()=>$broken->resolve([$field],$context));
            $blocked=new Nicode\FormStudio\Domain\FormSpec($other);
            $broken=new Nicode\FormStudio\Application\StagedMailAttachments($registry,$blocked,$reference,$values,null,$entries);
            $reject(fn()=>$broken->resolve([$field],new Nicode\FormStudio\Actions\ActionContext($blocked,[],$reference,'date')));
        }
        $group=Nicode\FormStudio\Domain\Uuid::create(); $rows=[Nicode\FormStudio\Domain\Uuid::create(),Nicode\FormStudio\Domain\Uuid::create()];
        $draft['elements']=[['uuid'=>$group,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>3]],['uuid'=>$field,'type'=>'field','parent_uuid'=>$group]];
        $spec=new Nicode\FormStudio\Domain\FormSpec($draft); $declarations=[$group=>$rows];
        $keys=array_map(static fn($row)=>$group.'/'.$row.'/'.$field,$rows);
        $entries=[$make('row one',$keys[0]),$make('row two',$keys[1])];
        $values=[$keys[1]=>[$entries[1]['receipt']],$keys[0]=>[$entries[0]['receipt']]];
        $resolver=new Nicode\FormStudio\Application\StagedMailAttachments($registry,$spec,$reference,$values,$declarations,array_reverse($entries));
        $context=new Nicode\FormStudio\Actions\ActionContext($spec,$values,$reference,'date',instances:$declarations,attachments:$resolver);
        same(['row one','row two'],array_column($resolver->resolve([$field],$context),'bytes'));
        $transport=new class implements Nicode\FormStudio\Contract\MailTransportInterface {
            public array $messages=[];
            public function send(Nicode\FormStudio\Actions\MailMessage $message):void { $this->messages[]=$message; }
        };
        $action=new Nicode\FormStudio\Actions\EmailAction($transport,new Nicode\FormStudio\Actions\TokenTemplate());
        $config=['to'=>['to@example.test'],'subject'=>'Subject','body_text'=>'Body','attachment_fields'=>[$field]];
        $action->execute($config,$context->forAction(Nicode\FormStudio\Domain\Uuid::create()));
        same(['row one','row two'],array_column($transport->messages[0]->attachments,'bytes'));
        $storage->delete($entries[0]['file']->key);
        $reject(fn()=>$action->execute($config,$context)); same(1,count($transport->messages));
    } finally { foreach($objects as $object) { $storage->delete($object->key); } }
});
