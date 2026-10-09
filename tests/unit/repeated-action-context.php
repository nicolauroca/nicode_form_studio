<?php
declare(strict_types=1);

test('repeated action tokens preserve row identity policy and escaping through memory transports', function (): void {
    [$group,$text,$secret,$password,$one,$two]=array_map(static fn()=>Nicode\FormStudio\Domain\Uuid::create(),range(1,6));
    $data=['schema_version'=>'1.0','uuid'=>Nicode\FormStudio\Domain\Uuid::create(),'name'=>'Fixture','elements'=>[['uuid'=>$group,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>2]]],'fields'=>[
        ['uuid'=>$text,'name'=>'answer','type'=>'text','persist'=>false],['uuid'=>$secret,'name'=>'private','type'=>'text','sensitive'=>true],['uuid'=>$password,'name'=>'password','type'=>'password','include_email'=>true],
    ],'rules'=>[]];
    foreach($data['fields'] as $field) { $data['elements'][]=['uuid'=>$field['uuid'],'type'=>'field','parent_uuid'=>$group]; }
    $key=static fn($field,$row)=>$group.'/'.$row.'/'.$field;
    $values=[$key($text,$one)=>'<b>one</b>',$key($text,$two)=>'two',$key($secret,$one)=>'hidden-secret',$key($password,$one)=>'hidden-password'];
    $context=new Nicode\FormStudio\Actions\ActionContext(new Nicode\FormStudio\Domain\FormSpec($data),$values,'synthetic-reference','date',[$key($text,$two)=>'Label two'],instances:[$group=>[$two,$one]]);
    $tokens=$context->emailTokens(); $token='field.'.$text.'.value';
    same([['instance_path'=>$group.'/'.$two,'value'=>'two'],['instance_path'=>$group.'/'.$one,'value'=>'<b>one</b>']],json_decode($tokens[$token],true));
    same('Label two',json_decode($tokens['field.'.$text.'.option_label'],true)[0]['value']);
    same(false,str_contains(json_encode($tokens),'hidden-')); same($context->instances,$context->forAction('fixture-action')->instances);
    $mail=new class implements Nicode\FormStudio\Contract\MailTransportInterface {
        public array $messages=[];
        public function send(Nicode\FormStudio\Actions\MailMessage $message):void { $this->messages[]=$message; }
    };
    (new Nicode\FormStudio\Actions\EmailAction($mail,new Nicode\FormStudio\Actions\TokenTemplate()))->execute(['to'=>['fixture@example.test'],'subject'=>'Fixture','body_text'=>'{{'.$token.'}}','body_html'=>'<p>{{'.$token.'}}</p>'],$context);
    same(1,count($mail->messages)); same(true,str_contains($mail->messages[0]->html,'&lt;b&gt;'));
    $http=new class implements Nicode\FormStudio\Contract\HttpTransportInterface {
        public array $bodies=[];
        public function request(string $url,string $method,array $headers,string $body,int $timeout=10):Nicode\FormStudio\Http\HttpResponse { $this->bodies[]=$body; return new Nicode\FormStudio\Http\HttpResponse(204,''); }
    };
    $secrets=new class implements Nicode\FormStudio\Contract\SecretStoreInterface { public function get(string $reference):string { throw new RuntimeException('No secret used by fixture.'); } };
    $webhook=new Nicode\FormStudio\Actions\WebhookAction($http,new Nicode\FormStudio\Http\DestinationPolicy(['hooks.example.test']),$secrets,new Nicode\FormStudio\Actions\TokenTemplate());
    $webhook->execute(['url'=>'https://hooks.example.test/fixture','payload'=>['rows'=>'{{'.$token.'}}']],$context->forAction('fixture-action'));
    same($tokens[$token],json_decode($http->bodies[0],true)['rows']);
    raises(InvalidArgumentException::class,fn()=>new Nicode\FormStudio\Actions\ActionContext($context->spec,[$text=>'unscoped'],'ref','date',instances:$context->instances));
});

test('repeated action conditions evaluate the whole expression within one lexical row', function (): void {
    [$group,$name,$number,$one,$two]=array_map(static fn()=>Nicode\FormStudio\Domain\Uuid::create(),range(1,5));
    $spec=new Nicode\FormStudio\Domain\FormSpec(['schema_version'=>'1.0','elements'=>[['uuid'=>$group,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>2]],['uuid'=>$name,'type'=>'field','parent_uuid'=>$group],['uuid'=>$number,'type'=>'field','parent_uuid'=>$group]],'fields'=>[['uuid'=>$name,'type'=>'text'],['uuid'=>$number,'type'=>'integer']],'rules'=>[]]);
    $key=static fn($field,$row)=>$group.'/'.$row.'/'.$field;
    $context=new Nicode\FormStudio\Actions\ActionContext($spec,[$key($name,$one)=>'A',$key($number,$one)=>10,$key($name,$two)=>'B',$key($number,$two)=>20],'ref','date',instances:[$group=>[$one,$two]]);
    $evaluator=new Nicode\FormStudio\Rules\ConditionEvaluator(Nicode\FormStudio\Registry\RuleOperatorRegistry::core());
    $condition=['group'=>'AND','children'=>[['field'=>$name,'operator'=>'equals','value'=>'A'],['field'=>$number,'operator'=>'equals','value'=>20]]];
    $types=[$name=>'string',$number=>'integer'];
    same(false,$context->matchesCondition($condition,$evaluator,$types));
    $condition['children'][1]['value']=10; same(true,$context->matchesCondition($condition,$evaluator,$types));
    $empty=new Nicode\FormStudio\Actions\ActionContext($spec,[],'ref','date',instances:[$group=>[]]);
    same(false,$empty->matchesCondition($condition,$evaluator,$types));
});
