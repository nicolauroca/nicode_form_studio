<?php
declare(strict_types=1);

// Synthetic action provider: records calls in memory; no mail, HTTP or other egress.
$raProvider=new class implements Nicode\FormStudio\Contract\ActionInterface {
    public int $calls=0; public array $tokens=[]; public bool $recover=false;
    public function id():string { return 'repeated-fixture'; }
    public function version():string { return '1.0.0'; }
    public function metadata():array { return ['retry'=>'definite_failure_only']; }
    public function validateConfiguration(array $configuration,string $path):array { return []; }
    public function execute(array $configuration,Nicode\FormStudio\Actions\ActionContext $context):Nicode\FormStudio\Actions\ActionOutcome {
        $this->calls++; $this->tokens=$context->emailTokens();
        if(!$this->recover) { throw new Nicode\FormStudio\Actions\ActionFailure('fixture_retry'); }
        return new Nicode\FormStudio\Actions\ActionOutcome('fixture_ok');
    }
};
$raRegistry=new Nicode\FormStudio\Registry\ActionRegistry(); $raRegistry->register($raProvider);
$raEngine=new Nicode\FormStudio\Actions\ActionEngine($raRegistry,new Nicode\FormStudio\Infrastructure\Database\ActionRunRepository($connection),new Nicode\FormStudio\Rules\ConditionEvaluator(Nicode\FormStudio\Registry\RuleOperatorRegistry::core()),$registry);
$raData=$rsData; $raId=Nicode\FormStudio\Domain\Uuid::create();
$raData['actions']=[['uuid'=>$raId,'type'=>$raProvider->id(),'config'=>[],'condition'=>['group'=>'AND','children'=>[['field'=>$rsName,'operator'=>'equals','value'=>"A'"],['field'=>$rsNumber,'operator'=>'equals','value'=>20]]]]];
$raSpec=new Nicode\FormStudio\Domain\FormSpec($raData);
$raVersion=$connection->insert('form_versions',['form_id'=>$rsForm,'revision'=>2,'schema_version'=>'1.0','spec'=>Nicode\FormStudio\Domain\CanonicalJson::encode($raData),'hash'=>$raSpec->hash,'published_at'=>gmdate('Y-m-d H:i:s'),'published_by'=>1,'comment'=>'Memory action snapshot','revoked_at'=>null]);
foreach([[10,20],[20,10]] as $pair) {
    $raw=[$rsGroup.'/'.$rsOne.'/'.$rsName=>"A'",$rsGroup.'/'.$rsTwo.'/'.$rsName=>'B',$rsGroup.'/'.$rsOne.'/'.$rsNumber=>$pair[0],$rsGroup.'/'.$rsTwo.'/'.$rsNumber=>$pair[1]];
    $valid=$validationEngine->validateInstances($raSpec,$rsRows,$raw);
    $saved=$submissions->persistInstances($rsForm,$raVersion,$raSpec,$rsRows,$valid,hash('sha256',random_bytes(32)));
    $context=new Nicode\FormStudio\Actions\ActionContext($raSpec,$valid->values,$saved->uuid,'date',instances:$rsRows);
    $result=$raEngine->execute($saved->id,$context);
    if($pair[0]===10) {
        if($result['actions'][$raId]!=='skipped' || $raProvider->calls!==0) { throw new RuntimeException('Action condition crossed rows.'); }
    } else {
        if($result['actions'][$raId]!=='failed' || $raProvider->calls!==1) { throw new RuntimeException('Repeated action failure not recorded once.'); }
        $raEngine->execute($saved->id,$context);
        if($raProvider->calls!==1) { throw new RuntimeException('Repeated replay retried a failed action implicitly.'); }
        $raProvider->recover=true;
        $jobId=$jobs->enqueue('action-retry',['form_id'=>$rsForm,'submission_id'=>$saved->id,'attempts'=>[$raId=>1]],1);
        $lease=$jobs->claim(); if($lease?->id!==$jobId) { throw new RuntimeException('Repeated retry lease mismatch.'); }
        $handler=new Nicode\FormStudio\Jobs\ActionRetryHandler($forms,$submissions,$jobs,$raEngine,static fn()=>true);
        $jobs->checkpoint($lease,$handler->run($lease,1));
        if($raProvider->calls!==2 || $jobs->get($jobId)['state']!=='completed') { throw new RuntimeException('Persisted repeated action retry failed.'); }
        $raEngine->execute($saved->id,$context,true,[$raId=>1]);
        if($raProvider->calls!==2 || count(json_decode($raProvider->tokens['field.'.$rsName.'.value'],true))!==2) { throw new RuntimeException('Repeated retry duplicated an effect or lost row tokens.'); }
    }
}
echo "Repeated actions: whole-row conditions, skipped vs failed state, in-memory tokens, persisted retry declarations and effect fencing passed.\n";

// Exercise the real EmailAction and retry handler with an in-memory transport.
// Nested declaration order must survive canonical JSON and later form versions.
foreach (['first_nonempty', 'last_nonempty', 'unique'] as $remSelection) {
    $remMail = new class implements Nicode\FormStudio\Contract\MailTransportInterface {
        public bool $fail = true; public array $messages = []; public int $calls = 0;
        public function send(Nicode\FormStudio\Actions\MailMessage $message): void {
            ++$this->calls;
            if ($this->fail) { throw new Nicode\FormStudio\Actions\ActionFailure('fixture_mail_failed'); }
            $this->messages[] = $message;
        }
    };
    $remForm = $forms->create('Repeated email retry', 'repeated-mail-' . bin2hex(random_bytes(5)), 1);
    $remData = $forms->draft($remForm);
    [$remOuter,$remInner,$remField,$remParentOne,$remParentTwo,$remChildOne,$remChildTwo,$remAction] = array_map(static fn()=>Nicode\FormStudio\Domain\Uuid::create(),range(1,8));
    $remData['elements'] = [
        ['uuid'=>$remOuter,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>2]],
        ['uuid'=>$remInner,'type'=>'repeatable-group','parent_uuid'=>$remOuter,'repeat'=>['min'=>0,'max'=>2]],
        ['uuid'=>$remField,'type'=>'field','parent_uuid'=>$remInner],
    ];
    $remData['fields'] = [['uuid'=>$remField,'name'=>'email','type'=>'email','config'=>[]]];
    $remData['actions'] = [['uuid'=>$remAction,'type'=>'email_autoresponse','config'=>['email_field'=>$remField,'email_field_selection'=>$remSelection,'reply_to_field'=>$remField,'reply_to_field_selection'=>$remSelection,'subject'=>'Received','body_text'=>'Thank you']]];
    $remSpec = new Nicode\FormStudio\Domain\FormSpec($remData);
    $remVersion = $connection->insert('form_versions',['form_id'=>$remForm,'revision'=>1,'schema_version'=>'1.0','spec'=>Nicode\FormStudio\Domain\CanonicalJson::encode($remData),'hash'=>$remSpec->hash,'published_at'=>gmdate('Y-m-d H:i:s'),'published_by'=>1,'comment'=>'Internal nested email retry snapshot','revoked_at'=>null]);
    $remRows = [$remOuter=>[$remParentTwo,$remParentOne],$remOuter.'/'.$remParentOne.'/'.$remInner=>[$remChildOne],$remOuter.'/'.$remParentTwo.'/'.$remInner=>[$remChildTwo]];
    $remValues = [$remOuter.'/'.$remParentOne.'/'.$remInner.'/'.$remChildOne.'/'.$remField=>'last@example.com',$remOuter.'/'.$remParentTwo.'/'.$remInner.'/'.$remChildTwo.'/'.$remField=>$remSelection==='unique'?'last@example.com':'first@example.com'];
    $remValid = $validationEngine->validateInstances($remSpec,$remRows,$remValues);
    if ($remValid->errors !== []) { throw new RuntimeException('Nested email fixture validation failed.'); }
    $remSaved = $submissions->persistInstances($remForm,$remVersion,$remSpec,$remRows,$remValid,hash('sha256',random_bytes(32)));
    $remContext = new Nicode\FormStudio\Actions\ActionContext($remSpec,$remValid->values,$remSaved->uuid,'date',instances:$remRows);
    $remRegistry = new Nicode\FormStudio\Registry\ActionRegistry();
    $remRegistry->register(new Nicode\FormStudio\Actions\EmailAction($remMail,new Nicode\FormStudio\Actions\TokenTemplate(),true));
    $remRuns = new Nicode\FormStudio\Infrastructure\Database\ActionRunRepository($connection);
    $remEngine = new Nicode\FormStudio\Actions\ActionEngine($remRegistry,$remRuns,new Nicode\FormStudio\Rules\ConditionEvaluator(Nicode\FormStudio\Registry\RuleOperatorRegistry::core()),$registry);
    if ($remEngine->execute($remSaved->id,$remContext)['actions'][$remAction] !== 'failed' || $remMail->calls !== 1) { throw new RuntimeException('Definite nested email failure was not recorded.'); }
    // Change both authoring and the active version before loading the retry.
    $remChanged = $remData; $remChanged['actions'][0]['config']['email_field_selection'] = $remSelection==='first_nonempty'?'last_nonempty':'first_nonempty';
    $forms->saveDraft($remForm,0,$remChanged,1);
    $remNewSpec = new Nicode\FormStudio\Domain\FormSpec($remChanged);
    $remNewVersion = $connection->insert('form_versions',['form_id'=>$remForm,'revision'=>2,'schema_version'=>'1.0','spec'=>Nicode\FormStudio\Domain\CanonicalJson::encode($remChanged),'hash'=>$remNewSpec->hash,'published_at'=>gmdate('Y-m-d H:i:s'),'published_by'=>1,'comment'=>'Changed internal selection snapshot','revoked_at'=>null]);
    $connection->execute('UPDATE '.$connection->table('forms').' SET published_version_id = :version WHERE id = :id',[':version'=>$remNewVersion,':id'=>$remForm]);
    $remMail->fail = false;
    $remRetries = new Nicode\FormStudio\Application\ActionRetries($connection,$forms,$submissions,$remRuns,$jobs,$remRegistry,static fn()=>true);
    $remAttempts = $remRetries->eligible(1,$remForm,$remSaved->id);
    if ($remAttempts !== [$remAction=>1]) { throw new RuntimeException('Nested email retry eligibility lost original action.'); }
    $remJob = $remRetries->enqueue(1,$remForm,$remSaved->id,$remAttempts);
    $remLease = $jobs->claim(); if ($remLease?->id !== $remJob) { throw new RuntimeException('Nested email retry job mismatch.'); }
    $remHandler = new Nicode\FormStudio\Jobs\ActionRetryHandler($forms,$submissions,$jobs,$remEngine,static fn()=>true);
    $jobs->checkpoint($remLease,$remHandler->run($remLease,1));
    $remExpected = $remSelection==='first_nonempty'?'first@example.com':'last@example.com';
    if (count($remMail->messages)!==1 || $remMail->messages[0]->to!==[$remExpected] || $remMail->messages[0]->replyTo!==$remExpected || $jobs->get($remJob)['state']!=='completed') { throw new RuntimeException('Nested email retry changed recipient order or snapshot policy.'); }
    $remEngine->execute($remSaved->id,$remContext,true,$remAttempts);
    if ($remMail->calls!==2 || $remRetries->eligible(1,$remForm,$remSaved->id)!==[]) { throw new RuntimeException('Stale retry repeated a delivered email.'); }
}
echo "Repeated email retries: nested persisted row order, all three policies, changed draft/live snapshot isolation, definite failure recovery and stale-attempt fencing passed with no external transport.\n";
