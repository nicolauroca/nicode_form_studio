<?php
declare(strict_types=1);
use Nicode\FormStudio\Actions\ReusableEmailTemplate;

test('reusable email templates bind named parameters without recipients or live field lookup', function (): void {
    $template = new ReusableEmailTemplate(); $draft = definition(); $uuid = $draft['fields'][0]['uuid'];
    $content = ['subject' => '{{form.name}}: {{input.contact.label}}', 'body_text' => '{{ input.contact.value }} / {{input.contact.option_label}}', 'body_html' => ''];
    same(['contact'], $template->validate($content));
    $bound = $template->bind($content, $draft, ['contact' => $uuid]);
    same('{{form.name}}: {{field.' . $uuid . '.label}}', $bound['subject']);
    same('{{field.' . $uuid . '.value}} / {{field.' . $uuid . '.option_label}}', $bound['body_text']); same(false, isset($bound['body_html']));
    raises(InvalidArgumentException::class, fn () => $template->bind($content, $draft, []));
    raises(InvalidArgumentException::class, fn () => $template->bind($content, $draft, ['contact' => $uuid, 'extra' => $uuid]));
    raises(InvalidArgumentException::class, fn () => $template->validate($content + ['to' => ['outside@example.test']]));
    raises(InvalidArgumentException::class, fn () => $template->validate(['subject' => "Unsafe\r\nBcc: other@example.test", 'body_text' => '']));
    raises(InvalidArgumentException::class, fn () => $template->validate(['subject' => 'Subject', 'body_text' => '{{field.' . $uuid . '.value}}']));
    $draft['fields'][0]['sensitive'] = true;
    raises(InvalidArgumentException::class, fn () => $template->bind($content, $draft, ['contact' => $uuid]));
    $draft['fields'][0]['include_email'] = true; same(true, isset($template->bind($content, $draft, ['contact' => $uuid])['subject']));
    $draft['fields'][0]['type'] = 'password'; raises(InvalidArgumentException::class, fn () => $template->bind($content, $draft, ['contact' => $uuid]));
});

test('copied email template provenance survives portable form export without mutable resource lookup', function (): void {
    $transport = new class implements Nicode\FormStudio\Contract\MailTransportInterface {
        public function send(Nicode\FormStudio\Actions\MailMessage $message): void { throw new LogicException('Export must not send mail.'); }
    };
    $actions = new Nicode\FormStudio\Registry\ProviderRegistry();
    $action = new Nicode\FormStudio\Actions\EmailAction($transport, new Nicode\FormStudio\Actions\TokenTemplate()); $actions->register($action);
    $pin = ['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'revision' => 3, 'hash' => str_repeat('a', 64)];
    $config = ['to' => ['fixture@example.test'], 'subject' => 'Saved {{form.name}}', 'body_text' => 'Saved content', 'template' => $pin, 'template_translations' => ['es-ES' => $pin]];
    same([], $action->validateConfiguration($config, '/action'));
    $draft = definition(); $draft['actions'] = [['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'type' => $action->id(), 'config' => $config]];
    $exchange = new Nicode\FormStudio\Transfer\DefinitionPackage(['fields' => registry(), 'actions' => $actions]);
    $package = $exchange->decode(Nicode\FormStudio\Domain\CanonicalJson::encode($exchange->export($draft)));
    same(Nicode\FormStudio\Domain\CanonicalJson::encode($config), Nicode\FormStudio\Domain\CanonicalJson::encode($package['definition']['actions'][0]['config']));
    $config['template']['revision'] = 0; same(true, count($action->validateConfiguration($config, '/action')) > 0);
});
