<?php
declare(strict_types=1);

use Nicode\FormStudio\Actions\ActionContext;
use Nicode\FormStudio\Actions\EmailAction;
use Nicode\FormStudio\Actions\MailMessage;
use Nicode\FormStudio\Actions\TokenTemplate;
use Nicode\FormStudio\Contract\MailTransportInterface;

test('template tokens are literal data and escape by output context', function (): void {
    $templates = new TokenTemplate();
    same('<b>&lt;script&gt;</b>', $templates->render('<b>{{value}}</b>', ['value' => '<script>'], 'html'));
    same('<?php system("bad"); ?>', $templates->render('{{value}}', ['value' => '<?php system("bad"); ?>']));
    raises(InvalidArgumentException::class, fn () => $templates->render('{{value}}', ['value' => "hello\r\nBcc: victim@example.com"], 'header'));
    raises(DomainException::class, fn () => $templates->render('{{unknown}}', []));
});
test('mail configuration rejects header injection and autoresponse uses validated email field', function (): void {
    $transport = new class implements MailTransportInterface {
        public array $messages = [];
        public function send(MailMessage $message): void { $this->messages[] = $message; }
    };
    $draft = definition(); $draft['fields'][0]['type'] = 'email'; $uuid = $draft['fields'][0]['uuid'];
    $context = new ActionContext(compiler()->compile($draft)->spec, [$uuid => 'visitor@example.com'], 'ref', '2026-09-26');
    $action = new EmailAction($transport, new TokenTemplate(), true);
    $config = ['email_field' => $uuid, 'subject' => 'Received {{submission.reference}}', 'body_text' => 'Thank you'];
    same([], $action->validateConfiguration($config, '/action'));
    same('mail_sent', $action->execute($config, $context)->code);
    same(['visitor@example.com'], $transport->messages[0]->to);
    raises(InvalidArgumentException::class, fn () => new MailMessage(["a@example.com\r\nBcc:x@example.com"], [], [], null, 'Subject', 'Body'));
});

test('bounded template expansion counts literal and escaped UTF-8 bytes without building oversized output', function (): void {
    $template=new TokenTemplate();
    same('á!', $template->render('{{value}}!',['value'=>'á'],maximumBytes:3));
    raises(LengthException::class,fn()=>$template->render('{{value}}!',['value'=>'á'],maximumBytes:2));
    same('&lt;', $template->render('{{value}}',['value'=>'<'],'html',4));
    raises(LengthException::class,fn()=>$template->render('{{value}}',['value'=>'<'],'html',3));
    same('', $template->render('{{long_token}}{{long_token}}',['long_token'=>''],maximumBytes:0));
    same('{{malformed', $template->render('{{malformed',[],maximumBytes:11));
    raises(LengthException::class,fn()=>$template->render('literal',[],maximumBytes:6));
    raises(InvalidArgumentException::class,fn()=>$template->render('',[],maximumBytes:-1));
    // Unbounded substitution would allocate roughly one GiB from this small template.
    raises(LengthException::class,fn()=>$template->render(str_repeat('{{value}}',1024),['value'=>str_repeat('x',1048576)],maximumBytes:1048576));
});
test('sensitive and password values never become default email summary tokens', function (): void {
    $draft = definition(); $uuid = $draft['fields'][0]['uuid']; $draft['fields'][0]['sensitive'] = true;
    $context = new ActionContext(compiler()->compile($draft)->spec, [$uuid => 'secret'], 'reference', 'date');
    same(false, str_contains(json_encode($context->emailTokens()), 'secret'));
});

test('email preparation failures are definite while transport exceptions retain their outcome', function (): void {
    $transport=new class implements MailTransportInterface {
        public int $calls=0; public ?Throwable $failure=null;
        public function send(MailMessage $message):void { $this->calls++; if($this->failure!==null) { throw $this->failure; } }
    };
    $draft=definition(); $field=$draft['fields'][0]['uuid'];
    $context=new ActionContext(compiler()->compile($draft)->spec,[$field=>"Answer\r\nBcc: injected@example.test"],'reference','date');
    $action=new EmailAction($transport,new TokenTemplate());
    $config=['to'=>['to@example.test'],'subject'=>'{{field.'.$field.'.value}}','body_text'=>'Body'];
    foreach([$config,array_replace($config,['subject'=>'Subject','body_text'=>'{{unavailable}}']),array_replace($config,['subject'=>'Subject','body_html'=>'{{unavailable}}'])] as $case) {
        try { $action->execute($case,$context); throw new RuntimeException('Expected preparation rejection.'); }
        catch(Nicode\FormStudio\Actions\ActionFailure $failure) { same('mail_preparation_failed',$failure->resultCode); same(false,$failure->unknownOutcome); }
    }
    same(0,$transport->calls);
    try { $action->execute(array_replace($config,['to'=>[]]),$context); throw new RuntimeException('Expected configuration rejection.'); }
    catch(Nicode\FormStudio\Actions\ActionFailure $failure) { same('configuration_invalid',$failure->resultCode); }
    same(0,$transport->calls);
    $config['subject']='Subject';
    foreach([new RuntimeException('Uncertain transport'),new Nicode\FormStudio\Actions\ActionFailure('mail_delivery_unknown',true)] as $failure) {
        $transport->failure=$failure;
        try { $action->execute($config,$context); throw new LogicException('Expected transport exception.'); }
        catch(Throwable $caught) { same($failure,$caught); }
    }
    same(2,$transport->calls);
});

test('repeated mail recipients require explicit selection in declaration order before sending', function (): void {
    $transport = new class implements MailTransportInterface {
        public array $messages = [];
        public function send(MailMessage $message): void { $this->messages[] = $message; }
    };
    $draft = definition(); $field = $draft['fields'][0]['uuid']; $draft['fields'][0]['type'] = 'email';
    $group = Nicode\FormStudio\Domain\Uuid::create();
    $draft['elements'][0]['parent_uuid'] = $group;
    array_unshift($draft['elements'], ['uuid'=>$group,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>3]]);
    $rows = array_map(static fn()=>Nicode\FormStudio\Domain\Uuid::create(), range(1,3));
    $keys = array_map(static fn($row)=>$group.'/'.$row.'/'.$field, $rows);
    $make = static fn(array $values, array $order) => new ActionContext(new Nicode\FormStudio\Domain\FormSpec($draft), $values, 'reference', 'date', instances:[$group=>$order]);
    $values = [$keys[2]=>'last@example.com', $keys[0]=>null, $keys[1]=>'first@example.com'];
    $action = new EmailAction($transport, new TokenTemplate(), true);
    $config = ['email_field'=>$field,'reply_to_field'=>$field,'subject'=>'Received','body_text'=>'Thank you'];
    raises(Nicode\FormStudio\Actions\ActionFailure::class, fn()=>$action->execute($config,$make($values,$rows)));
    same([], $transport->messages);
    $config += ['email_field_selection'=>'first_nonempty','reply_to_field_selection'=>'last_nonempty'];
    same([], $action->validateConfiguration($config,'/action'));
    $action->execute($config,$make($values,$rows));
    same(['first@example.com'],$transport->messages[0]->to); same('last@example.com',$transport->messages[0]->replyTo);
    $action->execute($config,$make($values,array_reverse($rows)));
    same(['last@example.com'],$transport->messages[1]->to); same('first@example.com',$transport->messages[1]->replyTo);
    $config['email_field_selection']='unique';
    raises(Nicode\FormStudio\Actions\ActionFailure::class, fn()=>$action->execute($config,$make($values,$rows)));
    $values[$keys[2]]='first@example.com'; $action->execute($config,$make($values,$rows));
    same(['first@example.com'],$transport->messages[2]->to);
    $config['email_field_selection']='first_nonempty'; $values[$keys[2]]="bad@example.com\r\nBcc: other@example.com";
    raises(Nicode\FormStudio\Actions\ActionFailure::class, fn()=>$action->execute($config,$make($values,$rows)));
    raises(Nicode\FormStudio\Actions\ActionFailure::class, fn()=>$action->execute($config,$make([],[])));
    same(3,count($transport->messages));
    $config['email_field_selection']='implicit'; same('action.email_selection',$action->validateConfiguration($config,'/action')[0]->code);
});


test('email format choice preserves legacy HTML and produces safe multipart fallback', function (): void {
    $transport = new class implements MailTransportInterface {
        public array $messages = [];
        public function send(MailMessage $message): void { $this->messages[] = $message; }
    };
    $draft = definition(); $uuid = $draft['fields'][0]['uuid'];
    $context = new ActionContext(compiler()->compile($draft)->spec, [$uuid => '<img src=x> & answer'], 'reference', 'date');
    $action = new EmailAction($transport, new TokenTemplate());
    $config = ['to'=>['to@example.test'], 'subject'=>'Answers', 'body_text'=>'Plain answer', 'body_html'=>'<p>{{field.'.$uuid.'.value}}</p>'];
    $action->execute($config, $context);
    same('<p>&lt;img src=x&gt; &amp; answer</p>', $transport->messages[0]->html);
    $action->execute($config + ['email_format'=>'text'], $context);
    same(null, $transport->messages[1]->html); same('Plain answer', $transport->messages[1]->text);
    unset($config['body_text']); $config['email_format']='html';
    same([], $action->validateConfiguration($config, '/action'));
    $action->execute($config, $context);
    same('<img src=x> & answer', $transport->messages[2]->text);
    same('<p>&lt;img src=x&gt; &amp; answer</p>', $transport->messages[2]->html);
    same(true, count($action->validateConfiguration(array_replace($config,['email_format'=>'invalid']), '/action')) > 0);
    same(true, count($action->validateConfiguration(array_replace($config,['body_html'=>'']), '/action')) > 0);
});
