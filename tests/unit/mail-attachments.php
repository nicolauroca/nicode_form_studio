<?php
declare(strict_types=1);

test('email resolves explicit attachments before transport and fails closed without a resolver', function (): void {
    $transport=new class implements Nicode\FormStudio\Contract\MailTransportInterface {
        public array $messages=[];
        public function send(Nicode\FormStudio\Actions\MailMessage $message):void { $this->messages[]=$message; }
    };
    $resolver=new class implements Nicode\FormStudio\Contract\MailAttachmentResolverInterface {
        public array $calls=[]; public ?Throwable $failure=null;
        public function resolve(array $selectedFields,Nicode\FormStudio\Actions\ActionContext $context):array {
            $this->calls[]=[$selectedFields,$context];
            if($this->failure!==null) { throw $this->failure; }
            return [new Nicode\FormStudio\Actions\MailAttachment('evidence.txt','text/plain','evidence')];
        }
    };
    $draft=definition(); $field=$draft['fields'][0]['uuid'];
    $context=new Nicode\FormStudio\Actions\ActionContext(compiler()->compile($draft)->spec,[],'reference','date');
    $config=['to'=>['to@example.test'],'subject'=>'Subject','body_text'=>'Body'];
    $action=new Nicode\FormStudio\Actions\EmailAction($transport,new Nicode\FormStudio\Actions\TokenTemplate(),attachments:$resolver);
    $action->execute($config,$context);
    same([],$resolver->calls); same([],$transport->messages[0]->attachments);
    $config['attachment_fields']=[$field];
    $action->execute($config,$context);
    same([[[$field],$context]],$resolver->calls); same('evidence',$transport->messages[1]->attachments[0]->bytes);
    foreach([null,'path',[$field,$field],['named'=>$field],['invalid'],[[]],array_fill(0,21,$field)] as $invalid) {
        $bad=array_replace($config,['attachment_fields'=>$invalid]);
        same('action.attachments',$action->validateConfiguration($bad,'/action')[0]->code);
        raises(Nicode\FormStudio\Actions\ActionFailure::class,fn()=>$action->execute($bad,$context));
    }
    $without=new Nicode\FormStudio\Actions\EmailAction($transport,new Nicode\FormStudio\Actions\TokenTemplate());
    $resolver->failure=new RuntimeException('private/storage/key');
    foreach([$without,$action] as $case) {
        try { $case->execute($config,$context); throw new RuntimeException('Expected attachment failure.'); }
        catch(Nicode\FormStudio\Actions\ActionFailure $failure) { same('mail_attachment_unavailable',$failure->resultCode); same(false,$failure->unknownOutcome); }
    }
    same(2,count($transport->messages));
});

test('mail attachments accept bounded typed bytes and reject paths headers and aggregate excess', function (): void {
    $attachment=new Nicode\FormStudio\Actions\MailAttachment('résumé.txt','text/plain',"one\0two");
    $message=static fn(array $files)=>new Nicode\FormStudio\Actions\MailMessage(['to@example.test'],[],[],null,'Subject','Body',attachments:$files);
    same([$attachment],$message([$attachment])->attachments);
    foreach(['','../secret','C:\\secret.txt',"bad\r\nX: yes",'.','..',str_repeat('x',256),"bad\xff"] as $name) { raises(InvalidArgumentException::class,fn()=>new Nicode\FormStudio\Actions\MailAttachment($name,'text/plain','bytes')); }
    foreach(['',"text/plain\r\nX: yes",'text/plain; charset=utf-8','not-a-type'] as $mime) { raises(InvalidArgumentException::class,fn()=>new Nicode\FormStudio\Actions\MailAttachment('file.txt',$mime,'bytes')); }
    raises(InvalidArgumentException::class,fn()=>$message(['path/to/file']));
    raises(InvalidArgumentException::class,fn()=>$message(['file'=>$attachment]));
    raises(InvalidArgumentException::class,fn()=>$message(array_fill(0,21,$attachment)));
    $limit=Nicode\FormStudio\Actions\MailAttachment::MAXIMUM_BYTES;
    $maximum=new Nicode\FormStudio\Actions\MailAttachment('maximum.bin','application/octet-stream',str_repeat('x',$limit));
    same([$maximum],$message([$maximum])->attachments);
    raises(LengthException::class,fn()=>$message([$maximum,$attachment]));
    raises(LengthException::class,fn()=>new Nicode\FormStudio\Actions\MailAttachment('excess.bin','application/octet-stream',str_repeat('x',$limit+1)));
});
