<?php
declare(strict_types=1);

(static function () use ($connection, $registry, $submissions, $jobs, $privateStorage, $storageProviders): void {
    $factory=new class implements Joomla\CMS\Mail\MailerFactoryInterface {
        public string $mode='preparation';
        public array $messages=[];
        public function createMailer(?Joomla\Registry\Registry $settings=null): Joomla\CMS\Mail\MailerInterface {
            $mail=new class extends Joomla\CMS\Mail\Mail {
                public string $mode=''; public int $sendCalls=0;
                public function addRecipient($recipient,$name='') { return $this->mode==='preparation'?false:parent::addRecipient($recipient,$name); }
                public function Send() { $this->sendCalls++; if($this->mode==='unknown') { return false; } return $this->preSend(); }
                public function postSend() { throw new LogicException('Database fixture must not deliver mail.'); }
            };
            $mail->mode=$this->mode; $this->messages[]=$mail; return $mail;
        }
    };
    $actions=new Nicode\FormStudio\Registry\ActionRegistry();
    $actions->register(new Nicode\FormStudio\Actions\EmailAction(new Nicode\FormStudio\Infrastructure\Joomla\MailTransport($factory,'sender@example.test','Fixture'),new Nicode\FormStudio\Actions\TokenTemplate(),attachments:new Nicode\FormStudio\Application\StoredMailAttachments($connection,$storageProviders)));
    $actions->register(new Nicode\FormStudio\Actions\EmailAction(new Nicode\FormStudio\Infrastructure\Joomla\MailTransport($factory,'sender@example.test','Fixture'),new Nicode\FormStudio\Actions\TokenTemplate(),true));
    $compiler=new Nicode\FormStudio\Compiler\FormCompiler($registry,$actions,new Nicode\FormStudio\Registry\ProviderRegistry(),new Nicode\FormStudio\Registry\ProviderRegistry());
    $forms=new Nicode\FormStudio\Infrastructure\Database\FormRepository($connection,$compiler);
    $form=$forms->create('Mail retry fixture','mail-retry-'.bin2hex(random_bytes(6)),1);
    $draft=$forms->draft($form); $action=Nicode\FormStudio\Domain\Uuid::create();
    $draft['actions']=[['uuid'=>$action,'type'=>'email_notification','enabled'=>true,'order'=>0,'failure_policy'=>'blocking','config'=>['to'=>['to@example.test'],'subject'=>'Original subject','body_text'=>'Original body']]];
    $fileField=Nicode\FormStudio\Domain\Uuid::create();
    $draft['elements']=[['uuid'=>$fileField,'type'=>'field']];
    $draft['fields']=[['uuid'=>$fileField,'name'=>'evidence','type'=>'file','config'=>['extensions'=>['txt'],'mime_types'=>['text/plain'],'max_bytes'=>1000]]];
    $draft['actions'][0]['config']['attachment_fields']=[$fileField];
    $revision=$forms->saveDraft($form,0,$draft,1); $version=$forms->publish($form,$revision,1); $spec=$forms->version($form,$version);
    $runs=new Nicode\FormStudio\Infrastructure\Database\ActionRunRepository($connection);
    $engine=new Nicode\FormStudio\Actions\ActionEngine($actions,$runs,new Nicode\FormStudio\Rules\ConditionEvaluator(Nicode\FormStudio\Registry\RuleOperatorRegistry::core()),$registry);
    $permission=static fn(int $actor,?int $form,string $permission):bool=>$actor===1;
    $retries=new Nicode\FormStudio\Application\ActionRetries($connection,$forms,$submissions,$runs,$jobs,$actions,$permission);
    $stream=fopen('php://temp','w+b'); fwrite($stream,'retry evidence'); rewind($stream); $object=$privateStorage->put($stream,100); fclose($stream);
    try {
    $receipt=['uuid'=>Nicode\FormStudio\Domain\Uuid::create(),'name'=>'evidence.txt','mime'=>'text/plain','size'=>$object->size];
    $response=$submissions->persist($form,$version,$spec,[$fileField=>$receipt],hash('sha256',random_bytes(32)));
    $connection->insert('submission_files',['uuid'=>$receipt['uuid'],'submission_id'=>$response->id,'field_uuid'=>$fileField,'provider'=>$object->provider,'storage_key'=>$object->key,'original_name'=>$receipt['name'],'mime'=>$receipt['mime'],'size_bytes'=>$object->size,'checksum'=>$object->checksum,'created_at'=>gmdate('Y-m-d H:i:s')]);
    $context=new Nicode\FormStudio\Actions\ActionContext($spec,[],$response->uuid,gmdate('Y-m-d H:i:s'));
    $result=$engine->execute($response->id,$context); $run=$runs->latest($response->id,$action);
    if($result['status']!=='blocking_failure' || $run['state']!=='failed' || $run['result_code']!=='mail_preparation_failed' || $factory->messages[0]->sendCalls!==0 || $retries->eligible(1,$form,$response->id)!==[$action=>1]) { throw new RuntimeException('Mail preparation failure did not persist as a retryable definite failure.'); }
    if($retries->eligible(2,$form,$response->id)!==[]) { throw new RuntimeException('Unauthorized mail retry offered.'); }
    $job=$retries->enqueue(1,$form,$response->id,[$action=>1]);
    // A retry must keep the response's original action snapshot after a new publication.
    $draft['actions'][0]['config']['subject']='Later subject';
    $draft['fields'][0]['include_email']=false;
    $draft['actions'][0]['config']['attachment_fields']=[];
    $revision=$forms->saveDraft($form,(int)$forms->get($form)['draft_revision'],$draft,1); $forms->publish($form,$revision,1);
    $factory->mode='prepared';
    $handler=new Nicode\FormStudio\Jobs\ActionRetryHandler($forms,$submissions,$jobs,$engine,$permission);
    $lease=$jobs->claim(); if($lease?->id!==$job) { throw new RuntimeException('Unexpected mail retry job claim.'); }
    $jobs->checkpoint($lease,$handler->run($lease,1));
    $retried=$runs->latest($response->id,$action);
    if($retried['state']!=='succeeded' || (int)$retried['attempt']!==2 || $jobs->get($job)['state']!=='completed' || $factory->messages[1]->Subject!=='Original subject' || $factory->messages[1]->sendCalls!==1 || $retries->eligible(1,$form,$response->id)!==[]) { throw new RuntimeException('Mail retry lost snapshot identity or completion fencing.'); }
    $attached=$factory->messages[1]->getAttachments();
    if(count($attached)!==1 || $attached[0][0]!=='retry evidence' || $attached[0][2]!=='evidence.txt' || $attached[0][5]!==true) { throw new RuntimeException('Mail retry did not recover original authorized attachment bytes.'); }
    $engine->execute($response->id,$context,true);
    if(count($factory->messages)!==2) { throw new RuntimeException('Successful mail replay created another mailer.'); }
    $factory->mode='unknown';
    $uncertain=$submissions->persist($form,$version,$spec,[],hash('sha256',random_bytes(32)));
    $uncertainContext=new Nicode\FormStudio\Actions\ActionContext($spec,[],$uncertain->uuid,gmdate('Y-m-d H:i:s'));
    $engine->execute($uncertain->id,$uncertainContext); $unknown=$runs->latest($uncertain->id,$action);
    if($unknown['state']!=='unknown' || $unknown['result_code']!=='mail_delivery_unknown' || $retries->eligible(1,$form,$uncertain->id)!==[]) { throw new RuntimeException('Uncertain mail delivery became retryable.'); }
    try { $retries->enqueue(1,$form,$uncertain->id,[$action=>1]); throw new RuntimeException('Unknown delivery accepted retry.'); } catch(DomainException) {}
    $engine->execute($uncertain->id,$uncertainContext,true);
    if(count($factory->messages)!==3 || $factory->messages[2]->sendCalls!==1) { throw new RuntimeException('Unknown delivery was retried.'); }
    $tokenForm=$forms->create('Mail token failure','mail-token-'.bin2hex(random_bytes(6)),1);
    $tokenDraft=$forms->draft($tokenForm); $field=Nicode\FormStudio\Domain\Uuid::create();
    $tokenDraft['elements']=[['uuid'=>$field,'type'=>'field']];
    $tokenDraft['fields']=[['uuid'=>$field,'name'=>'answer','type'=>'textarea','config'=>['label'=>'Answer']]];
    $tokenDraft['actions']=$draft['actions']; $tokenDraft['actions'][0]['config']['subject']='Answer {{field.'.$field.'.value}}';
    unset($tokenDraft['actions'][0]['config']['attachment_fields']);
    $tokenRevision=$forms->saveDraft($tokenForm,0,$tokenDraft,1); $tokenVersion=$forms->publish($tokenForm,$tokenRevision,1); $tokenSpec=$forms->version($tokenForm,$tokenVersion);
    $values=[$field=>"Synthetic answer\r\nBcc: injected@example.test"];
    $tokenResponse=$submissions->persist($tokenForm,$tokenVersion,$tokenSpec,$values,hash('sha256',random_bytes(32)));
    $tokenContext=new Nicode\FormStudio\Actions\ActionContext($tokenSpec,$values,$tokenResponse->uuid,gmdate('Y-m-d H:i:s'));
    $engine->execute($tokenResponse->id,$tokenContext); $tokenRun=$runs->latest($tokenResponse->id,$action);
    if($tokenRun['state']!=='failed' || $tokenRun['result_code']!=='mail_preparation_failed' || count($factory->messages)!==3 || $retries->eligible(1,$tokenForm,$tokenResponse->id)!==[$action=>1]) { throw new RuntimeException('Rejected subject token was mislabeled as uncertain delivery or reached a mailer.'); }
    $privateStorage->delete($object->key);
    $stream=fopen('php://temp','w+b'); fwrite($stream,'retry evidence'); rewind($stream); $object=$privateStorage->put($stream,100); fclose($stream);
    $factory->mode='preparation'; $receipt['uuid']=Nicode\FormStudio\Domain\Uuid::create();
    $lost=$submissions->persist($form,$version,$spec,[$fileField=>$receipt],hash('sha256',random_bytes(32)));
    $connection->insert('submission_files',['uuid'=>$receipt['uuid'],'submission_id'=>$lost->id,'field_uuid'=>$fileField,'provider'=>$object->provider,'storage_key'=>$object->key,'original_name'=>$receipt['name'],'mime'=>$receipt['mime'],'size_bytes'=>$object->size,'checksum'=>$object->checksum,'created_at'=>gmdate('Y-m-d H:i:s')]);
    $engine->execute($lost->id,new Nicode\FormStudio\Actions\ActionContext($spec,[],$lost->uuid,gmdate('Y-m-d H:i:s')));
    if(count($factory->messages)!==4 || $runs->latest($lost->id,$action)['result_code']!=='mail_preparation_failed') { throw new RuntimeException('Missing-file retry fixture failed preparation unexpectedly.'); }
    $lostJob=$retries->enqueue(1,$form,$lost->id,[$action=>1]);
    $privateStorage->delete($object->key); $factory->mode='prepared';
    $lostLease=$jobs->claim(); if($lostLease?->id!==$lostJob) { throw new RuntimeException('Unexpected missing-attachment retry claim.'); }
    $jobs->checkpoint($lostLease,$handler->run($lostLease,1));
    $lostRun=$runs->latest($lost->id,$action);
    if($lostRun['state']!=='failed' || $lostRun['result_code']!=='mail_attachment_unavailable' || (int)$lostRun['attempt']!==2 || count($factory->messages)!==4) { throw new RuntimeException('Missing attachment retry sent an incomplete message or lost definite failure.'); }
    } finally { $privateStorage->delete($object->key); }
    require __DIR__.'/database-mail-locale-retries.php';
})();
echo "Joomla mail action persistence: preparation failure, authorized pinned-snapshot retry, success replay and unknown-delivery exclusion passed without delivery.\n";
