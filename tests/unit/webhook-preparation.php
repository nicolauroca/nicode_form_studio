<?php
declare(strict_types=1);

test('webhook preparation rejects expanded limits and invalid secrets before transport but preserves uncertain outcomes',function():void {
    $http=new class implements Nicode\FormStudio\Contract\HttpTransportInterface {
        public array $requests=[]; public ?Throwable $failure=null;
        public function request(string $url,string $method,array $headers,string $body,int $timeout=10):Nicode\FormStudio\Http\HttpResponse {
            $this->requests[]=compact('headers','body'); if($this->failure!==null) { throw $this->failure; }
            return new Nicode\FormStudio\Http\HttpResponse(204,'');
        }
    };
    $secrets=new class implements Nicode\FormStudio\Contract\SecretStoreInterface {
        public string $value='synthetic-token'; public int $calls=0;
        public function get(string $reference):string { $this->calls++; return $this->value; }
    };
    $action=new Nicode\FormStudio\Actions\WebhookAction($http,new Nicode\FormStudio\Http\DestinationPolicy(['hooks.example.com']),$secrets,new Nicode\FormStudio\Actions\TokenTemplate());
    $draft=definition(); $field=$draft['fields'][0]['uuid']; $spec=compiler()->compile($draft)->spec;
    $context=static fn(string $value)=>(new Nicode\FormStudio\Actions\ActionContext($spec,[$field=>$value],'reference','date'))->forAction('action-1');
    $token='{{field.'.$field.'.value}}'; $base=['url'=>'https://hooks.example.com/hook'];
    $reject=static function(array $config,string $value,string $code)use($action,$context,$http):void {
        $before=count($http->requests);
        try { $action->execute($config,$context($value)); throw new RuntimeException('Expected preflight rejection.'); }
        catch(Nicode\FormStudio\Actions\ActionFailure $failure) { same($code,$failure->resultCode); same(false,$failure->unknownOutcome); same($code,$failure->getMessage()); }
        same($before,count($http->requests));
    };
    $reject($base+['headers'=>['X-Test'=>$token]],"value\r\nInjected: yes",'webhook_preparation_failed');
    $reject($base+['payload'=>['a'=>'{{unavailable}}']],'value','webhook_preparation_failed');
    $reject($base+['payload'=>['a'=>$token]],"\xff",'webhook_preparation_failed');
    $reject($base+['payload'=>['a'=>$token],'bearer_secret'=>'fixture.token'],str_repeat('x',1048576-7),'webhook_request_limit');
    $reject($base+['headers'=>['X-Test'=>$token],'bearer_secret'=>'fixture.token'],str_repeat('x',8193),'webhook_request_limit');
    same(0,$secrets->calls);
    $reject($base+['payload'=>['a'=>$token,'b'=>$token],'bearer_secret'=>'fixture.token'],str_repeat('x',600000),'webhook_request_limit');
    $reject($base+['payload'=>['a'=>$token]],str_repeat('"',530000),'webhook_request_limit');
    $reject($base+['payload'=>['a'=>str_repeat($token,1024)]],str_repeat('x',1048576),'webhook_request_limit');
    same(0,$secrets->calls);
    $action->execute($base+['payload'=>['a'=>$token]],$context(str_repeat('x',1048576-8)));
    same(1048576,strlen($http->requests[0]['body']));
    $action->execute($base+['headers'=>['X-Test'=>$token]],$context(str_repeat('x',8192)));
    same(8192,strlen($http->requests[1]['headers']['X-Test']));
    foreach(['',"token\r\nInjected: yes",'token with space',str_repeat('x',8186)] as $secret) {
        $secrets->value=$secret; $reject($base+['bearer_secret'=>'fixture.token'],'value','secret_unavailable');
    }
    $secrets->value=str_repeat('x',8185);
    $action->execute($base+['bearer_secret'=>'fixture.token'],$context('value'));
    same(8192,strlen($http->requests[2]['headers']['Authorization']));
    foreach([new RuntimeException('Uncertain external transport'),new Nicode\FormStudio\Actions\ActionFailure('http_delivery_unknown',true)] as $failure) {
        $http->failure=$failure;
        try { $action->execute($base,$context('value')); throw new LogicException('Expected transport exception.'); }
        catch(Throwable $caught) { same($failure,$caught); }
    }
});
