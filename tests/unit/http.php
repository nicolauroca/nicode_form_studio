<?php
declare(strict_types=1);

use Nicode\FormStudio\Http\DestinationPolicy;
use Nicode\FormStudio\Http\PublicAddress;
use Nicode\FormStudio\Actions\WebhookAction;
use Nicode\FormStudio\Actions\ActionContext;
use Nicode\FormStudio\Actions\TokenTemplate;
use Nicode\FormStudio\Actions\ActionFailure;

test('SSRF policy rejects special addresses including mapped IPv6 and mixed DNS answers', function (): void {
    foreach (['127.0.0.1', '0.0.0.0', '10.2.3.4', '100.64.2.1', '169.254.169.254', '172.16.1.1', '192.168.0.1', '192.0.2.8', '198.18.1.1', '224.1.1.1', '255.255.255.255', '::1', '::ffff:127.0.0.1', 'fd00::1', 'fe80::1', '2001:db8::1', '2002:7f00:1::', '3fff::1'] as $ip) { same(false, PublicAddress::allowed($ip)); }
    foreach (['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '2001:4860:4860::8888'] as $ip) { same(true, PublicAddress::allowed($ip)); }
    $mixed = new DestinationPolicy(['hooks.example.com'], static fn () => ['1.1.1.1', '127.0.0.1']);
    raises(DomainException::class, fn () => $mixed->resolve('https://hooks.example.com/hook'));
    $policy = new DestinationPolicy(['hooks.example.com'], static fn () => ['1.1.1.1']);
    same('hooks.example.com', $policy->resolve('https://hooks.example.com/hook')['host']);
    foreach (['http://hooks.example.com', 'https://hooks.example.com:8443', 'https://hooks.example.com.evil.test', 'https://user@hooks.example.com', 'https://127.0.0.1', 'https://hooks.example.com/#fragment', "https://hooks.example.com\r\nx", 'https://hooks.example.com\\@evil.test'] as $url) { raises(DomainException::class, fn () => $policy->resolve($url)); }
});

test('webhook payload is encoded, signed with server secret and carries action specific retry identity', function (): void {
    $http = new class implements Nicode\FormStudio\Contract\HttpTransportInterface {
        public array $requests = []; public int $status = 204;
        public function request(string $url, string $method, array $headers, string $body, int $timeout = 10): Nicode\FormStudio\Http\HttpResponse { $this->requests[] = compact('url', 'method', 'headers', 'body'); return new Nicode\FormStudio\Http\HttpResponse($this->status, ''); }
    };
    $secrets = new class implements Nicode\FormStudio\Contract\SecretStoreInterface { public function get(string $reference): string { return 'test-secret'; } };
    $policy = new DestinationPolicy(['hooks.example.com']);
    $action = new WebhookAction($http, $policy, $secrets, new TokenTemplate());
    $draft = definition(); $uuid = $draft['fields'][0]['uuid'];
    $context = (new ActionContext(compiler()->compile($draft)->spec, [$uuid => '"quoted"'], 'reference', 'date'))->forAction('action-1');
    $config = ['url' => 'https://hooks.example.com/hook', 'payload' => ['answer' => '{{field.' . $uuid . '.value}}'], 'signing_secret' => 'webhook.signature'];
    same([], $action->validateConfiguration($config, '/action'));
    same('webhook_delivered', $action->execute($config, $context)->code);
    $request = $http->requests[0]; same(['answer' => '"quoted"'], json_decode($request['body'], true));
    same('sha256=' . hash_hmac('sha256', $request['headers']['X-FormStudio-Timestamp'] . '.' . $request['body'], 'test-secret'), $request['headers']['X-FormStudio-Signature']);
    $action->execute($config, $context); same($request['headers']['Idempotency-Key'], $http->requests[1]['headers']['Idempotency-Key']);
    $action->execute($config, $context->forAction('action-2')); same(false, $request['headers']['Idempotency-Key'] === $http->requests[2]['headers']['Idempotency-Key']);
    $http->status = 503;
    try { $action->execute($config, $context); throw new RuntimeException('Expected unknown webhook outcome.'); } catch (ActionFailure $error) { same(true, $error->unknownOutcome); }
});

test('webhook signing headers and case-insensitive identities cannot be shadowed before delivery', function (): void {
    $http=new class implements Nicode\FormStudio\Contract\HttpTransportInterface {
        public int $calls=0;
        public function request(string $url,string $method,array $headers,string $body,int $timeout=10):Nicode\FormStudio\Http\HttpResponse { $this->calls++; return new Nicode\FormStudio\Http\HttpResponse(204,''); }
    };
    $secrets=new class implements Nicode\FormStudio\Contract\SecretStoreInterface { public int $calls=0; public function get(string $reference):string { $this->calls++; return 'test-secret'; } };
    $policy=new DestinationPolicy(['hooks.example.com']); $action=new WebhookAction($http,$policy,$secrets,new TokenTemplate());
    $context=(new ActionContext(compiler()->compile(definition())->spec,[],'reference','date'))->forAction('action-1');
    foreach([['X-FormStudio-Signature'=>'forged'],['X-FORMSTUDIO-SIGNATURE'=>'forged'],['X-formstudio-timestamp'=>'0'],['X-Custom'=>'one','X-CUSTOM'=>'two']] as $headers) {
        $configuration=['url'=>'https://hooks.example.com/hook','headers'=>$headers,'signing_secret'=>'fixture.key'];
        same(true,in_array('action.webhook_header_identity',array_column($action->validateConfiguration($configuration,'/action'),'code'),true));
        try { $action->execute($configuration,$context); throw new RuntimeException('Ambiguous headers accepted.'); }
        catch(ActionFailure $error) { same('configuration_invalid',$error->resultCode); same(false,$error->unknownOutcome); }
    }
    same(0,$http->calls); same(0,$secrets->calls);
    same([],$action->validateConfiguration(['url'=>'https://hooks.example.com/hook','headers'=>['X-Trace'=>'one','X-Other'=>'two']],'/action'));
    $dnsCalls=0;
    $transport=new Nicode\FormStudio\Http\CurlTransport(new DestinationPolicy(['hooks.example.com'],static function()use(&$dnsCalls):array { $dnsCalls++; return ['1.1.1.1']; }));
    raises(InvalidArgumentException::class,fn()=>$transport->request('https://hooks.example.com/hook','POST',['Authorization'=>'one','authorization'=>'two'],'{}'));
    same(0,$dnsCalls);
});
