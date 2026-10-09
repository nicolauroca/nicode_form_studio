<?php
declare(strict_types=1);

function publicationActionCompiler(): Nicode\FormStudio\Compiler\FormCompiler {
    $mail = new class implements Nicode\FormStudio\Contract\MailTransportInterface {
        public function send(Nicode\FormStudio\Actions\MailMessage $message): void { throw new RuntimeException('Compilation must not send mail.'); }
    };
    $http = new class implements Nicode\FormStudio\Contract\HttpTransportInterface {
        public function request(string $url, string $method, array $headers, string $body, int $timeout = 10): Nicode\FormStudio\Http\HttpResponse { throw new RuntimeException('Compilation must not call HTTP.'); }
    };
    $secrets = new class implements Nicode\FormStudio\Contract\SecretStoreInterface {
        public function get(string $reference): string { throw new RuntimeException('Compilation must not resolve secret values.'); }
    };
    $actions = new Nicode\FormStudio\Registry\ActionRegistry(); $tokens = new Nicode\FormStudio\Actions\TokenTemplate();
    $actions->register(new Nicode\FormStudio\Actions\EmailAction($mail, $tokens));
    $actions->register(new Nicode\FormStudio\Actions\EmailAction($mail, $tokens, true));
    $actions->register(new Nicode\FormStudio\Actions\WebhookAction($http, new Nicode\FormStudio\Http\DestinationPolicy(['hooks.example.com'], static function (): array { throw new RuntimeException('Compilation must not query DNS.'); }), $secrets, $tokens));
    return new Nicode\FormStudio\Compiler\FormCompiler(registry(), $actions, new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry());
}

test('mail publication validates recipient field types header safety copied templates and token disclosure', function (): void {
    $compiler = publicationActionCompiler(); $draft = withSecond(definition(), 'email');
    [$text, $email] = array_column($draft['fields'], 'uuid');
    $normal = ['to' => ['staff@example.com'], 'subject' => 'Received {{submission.reference}}', 'body_text' => '{{field.' . $text . '.value}}'];
    $cases = [
        ['email_notification', ['to' => []], 'action.recipients'],
        ['email_notification', ['to' => ["staff@example.com\r\nBcc:other@example.com"]], 'action.address'],
        ['email_notification', ['cc' => 'staff@example.com'], 'action.addresses'],
        ['email_notification', ['subject' => "Hello\r\nBcc:x"], 'action.subject'],
        ['email_notification', ['body_text' => []], 'action.body'],
        ['email_notification', ['reply_to_field' => $text], 'action.email_reference'],
        ['email_notification', ['body_html' => '{{secret}}'], 'template.token'],
        ['email_notification', ['template' => ['uuid' => $text, 'revision' => 0, 'hash' => str_repeat('a',64)]], 'action.template'],
        ['email_autoresponse', ['email_field' => $text], 'action.email_reference'],
        ['email_autoresponse', ['email_field' => Nicode\FormStudio\Domain\Uuid::create()], 'action.email_reference'],
    ];
    foreach ($cases as [$type,$override,$code]) {
        $draft['actions'] = [['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'type' => $type, 'config' => array_replace($normal,$override)]];
        $result = $compiler->compile($draft); same(false,$result->successful()); same(true,in_array($code,array_column($result->diagnostics,'code'),true));
    }
    foreach (['email_notification','email_autoresponse'] as $type) {
        $draft['actions'] = [['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'type' => $type, 'config' => $normal + ['email_field' => $email, 'reply_to_field' => $email]]];
        same(true,$compiler->compile($draft)->successful());
        $draft['fields'][0]['sensitive'] = true;
        same(false,$compiler->compile($draft)->successful());
        $draft['fields'][0]['include_email'] = true;
        same(true,$compiler->compile($draft)->successful());
        unset($draft['fields'][0]['sensitive'],$draft['fields'][0]['include_email']);
    }
});

test('webhook publication validates destinations bounds maps secret references and excluded tokens without outbound IO', function (): void {
    $compiler = publicationActionCompiler(); $draft = definition(); $field = $draft['fields'][0]['uuid'];
    $normal = ['url' => 'https://hooks.example.com/receive', 'payload' => ['answer' => '{{field.' . $field . '.value}}'], 'signing_secret' => 'APPROVED_REFERENCE'];
    $cases = [
        ['headers' => ['X-FORMSTUDIO-SIGNATURE' => 'forged']],
        ['headers' => ['X-FormStudio-Timestamp' => '0']],
        ['headers' => ['X-Custom' => 'one', 'X-CUSTOM' => 'two']],
        ['url' => 'https://unapproved.example.com'], ['url' => 'http://hooks.example.com'],
        ['method' => 'GET'], ['timeout' => 0], ['timeout' => 31], ['timeout' => '10'],
        ['payload' => ['answer' => ['nested']]], ['headers' => ['Authorization' => 'inline credential']],
        ['headers' => ['X-Test' => "value\r\nInjected: yes"]], ['bearer_secret' => '../secret'],
        ['payload' => ['answer' => '{{missing}}']], ['headers' => ['X-Test' => '{{missing}}']],
    ];
    foreach ($cases as $override) {
        $draft['actions'] = [['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'type' => 'webhook', 'config' => array_replace($normal,$override)]];
        $result = $compiler->compile($draft); same(false,$result->successful());
        same(true,count(array_filter($result->diagnostics,static fn ($error): bool => str_starts_with($error->path,'/actions/0'))) > 0);
    }
    foreach (['POST','PUT','PATCH'] as $method) {
        $draft['actions'] = [['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'type' => 'webhook', 'config' => $normal + ['method' => $method, 'timeout' => 30, 'headers' => ['X-Reference' => '{{submission.reference}}']]]];
        same(true,$compiler->compile($draft)->successful());
    }
    $draft['fields'][0]['include_email'] = false;
    same(false,$compiler->compile($draft)->successful());
});
