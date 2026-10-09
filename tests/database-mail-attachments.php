<?php
declare(strict_types=1);

(static function()use($forms,$submissions,$connection,$privateStorage,$storageProviders):void {
    $form=$forms->create('Mail attachment ownership','mail-files-'.bin2hex(random_bytes(6)),1);
    $draft=$forms->draft($form); [$field,$excluded]=array_map(static fn()=>Nicode\FormStudio\Domain\Uuid::create(),[1,2]);
    $draft['elements']=[['uuid'=>$field,'type'=>'field'],['uuid'=>$excluded,'type'=>'field']];
    $config=['extensions'=>['txt'],'mime_types'=>['text/plain'],'max_bytes'=>1000];
    $draft['fields']=[['uuid'=>$field,'name'=>'allowed','type'=>'file','sensitive'=>true,'include_email'=>true,'config'=>$config],['uuid'=>$excluded,'name'=>'excluded','type'=>'file','sensitive'=>true,'config'=>$config]];
    $revision=$forms->saveDraft($form,0,$draft,1); $version=$forms->publish($form,$revision,1); $spec=$forms->version($form,$version);
    $receipt=['uuid'=>Nicode\FormStudio\Domain\Uuid::create(),'name'=>'evidence.txt','mime'=>'text/plain','size'=>8];
    $response=$submissions->persist($form,$version,$spec,[$field=>$receipt],hash('sha256',random_bytes(32)));
    $stream=fopen('php://temp','w+b'); fwrite($stream,'evidence'); rewind($stream); $object=$privateStorage->put($stream,100); fclose($stream);
    $connection->insert('submission_files',['uuid'=>$receipt['uuid'],'submission_id'=>$response->id,'field_uuid'=>$field,'provider'=>'local','storage_key'=>$object->key,'original_name'=>$receipt['name'],'mime'=>$receipt['mime'],'size_bytes'=>$object->size,'checksum'=>$object->checksum,'created_at'=>gmdate('Y-m-d H:i:s')]);
    $resolver=new Nicode\FormStudio\Application\StoredMailAttachments($connection,$storageProviders);
    $context=new Nicode\FormStudio\Actions\ActionContext($spec,[$field=>$receipt],$response->uuid,gmdate('Y-m-d H:i:s'));
    $reject=static function(array $selection,Nicode\FormStudio\Actions\ActionContext $context)use($resolver):void {
        try { $resolver->resolve($selection,$context); throw new RuntimeException('Expected attachment denial.'); }
        catch(Nicode\FormStudio\Actions\ActionFailure $failure) { if($failure->resultCode!=='mail_attachment_unavailable' || $failure->unknownOutcome) { throw new RuntimeException('Unsafe attachment failure result.'); } }
    };
    try {
        $files=$resolver->resolve([$field],$context);
        if(count($files)!==1 || $files[0]->bytes!=='evidence' || $files[0]->name!=='evidence.txt' || $files[0]->mimeType!=='text/plain') { throw new RuntimeException('Authorized attachment bytes changed.'); }
        $mail=new class implements Nicode\FormStudio\Contract\MailTransportInterface {
            public array $messages=[];
            public function send(Nicode\FormStudio\Actions\MailMessage $message):void { $this->messages[]=$message; }
        };
        $action=new Nicode\FormStudio\Actions\EmailAction($mail,new Nicode\FormStudio\Actions\TokenTemplate(),attachments:$resolver);
        $configuration=['to'=>['to@example.test'],'subject'=>'Evidence','body_text'=>'Stored attachment','attachment_fields'=>[$field]];
        $action->execute($configuration,$context);
        if(count($mail->messages)!==1 || $mail->messages[0]->attachments[0]->bytes!=='evidence') { throw new RuntimeException('Stored attachment did not reach mail action.'); }
        foreach([[$excluded],[$field,$field],['invalid'],['named'=>$field]] as $selection) { $reject($selection,$context); }
        $forged=new Nicode\FormStudio\Actions\ActionContext($spec,[$field=>['uuid'=>Nicode\FormStudio\Domain\Uuid::create()]],$response->uuid,gmdate('Y-m-d H:i:s'));
        if($resolver->resolve([$field],$forged)[0]->bytes!=='evidence') { throw new RuntimeException('Attachment resolver trusted caller values instead of canonical receipt.'); }
        $draft['fields'][0]['include_email']=false; $revision=$forms->saveDraft($form,(int)$forms->get($form)['draft_revision'],$draft,1); $newVersion=$forms->publish($form,$revision,1);
        if($resolver->resolve([$field],$context)[0]->bytes!=='evidence') { throw new RuntimeException('Live policy changed historical attachment policy.'); }
        $reject([$field],new Nicode\FormStudio\Actions\ActionContext($forms->version($form,$newVersion),[],$response->uuid,gmdate('Y-m-d H:i:s')));
        $connection->execute('UPDATE '.$connection->table('submission_files').' SET checksum=:checksum WHERE uuid=:uuid',[':checksum'=>str_repeat('0',64),':uuid'=>$receipt['uuid']]);
        $reject([$field],$context);
        $connection->execute('UPDATE '.$connection->table('submission_files').' SET checksum=:checksum, field_uuid=:field WHERE uuid=:uuid',[':checksum'=>$object->checksum,':field'=>$excluded,':uuid'=>$receipt['uuid']]);
        $reject([$field],$context);
        $connection->execute('UPDATE '.$connection->table('submission_files').' SET field_uuid=:field, size_bytes=:size WHERE uuid=:uuid',[':field'=>$field,':size'=>9,':uuid'=>$receipt['uuid']]);
        $reject([$field],$context);
        $connection->execute('UPDATE '.$connection->table('submission_files').' SET size_bytes=:size WHERE uuid=:uuid',[':size'=>8,':uuid'=>$receipt['uuid']]);
        $other=$submissions->persist($form,$version,$spec,[$field=>$receipt],hash('sha256',random_bytes(32)));
        $reject([$field],new Nicode\FormStudio\Actions\ActionContext($spec,[$field=>$receipt],$other->uuid,gmdate('Y-m-d H:i:s')));
        $connection->execute('UPDATE '.$connection->table('submissions').' SET anonymized_at=:now WHERE id=:id',[':now'=>gmdate('Y-m-d H:i:s'),':id'=>$response->id]);
        $reject([$field],$context);
        try { $action->execute($configuration,$context); throw new RuntimeException('Expected anonymized attachment action rejection.'); }
        catch(Nicode\FormStudio\Actions\ActionFailure $failure) { if($failure->resultCode!=='mail_attachment_unavailable' || $failure->unknownOutcome) { throw new RuntimeException('Unsafe attachment action failure.'); } }
        if(count($mail->messages)!==1) { throw new RuntimeException('Attachment rejection reached mail transport.'); }
        foreach([['full',false],['metadata',true],['none',true]] as [$mode,$persist]) {
            $draft['persistence']['mode']=$mode; $draft['fields'][0]['persist']=$persist; $draft['fields'][0]['include_email']=true;
            $revision=$forms->saveDraft($form,(int)$forms->get($form)['draft_revision'],$draft,1); $ephemeralVersion=$forms->publish($form,$revision,1); $ephemeralSpec=$forms->version($form,$ephemeralVersion);
            $ephemeral=$submissions->persist($form,$ephemeralVersion,$ephemeralSpec,[$field=>$receipt],hash('sha256',random_bytes(32)));
            $reject([$field],new Nicode\FormStudio\Actions\ActionContext($ephemeralSpec,[],$ephemeral->uuid,gmdate('Y-m-d H:i:s')));
        }
    } finally { $privateStorage->delete($object->key); }
})();
echo "Stored mail attachment resolver: historical opt-in, original snapshot, canonical ownership, checksum/size, foreign receipts and anonymization exclusion passed.\n";
