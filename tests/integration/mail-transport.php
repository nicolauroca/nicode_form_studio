<?php
declare(strict_types=1);

/** Real Joomla/PHPMailer composition, with delivery unconditionally replaced by MIME preparation. */
final class PreparedJoomlaMail extends Joomla\CMS\Mail\Mail
{
    public string $prepared = '';
    public string $outcome = 'prepared';
    public int $sendCalls = 0;
    private function rejectPreparation(string $method): bool
    {
        if ($this->outcome === $method.'.throw') { throw new RuntimeException('Synthetic private preparation detail'); }
        return $this->outcome === $method.'.false';
    }
    public function setSender($from, $name = '') { return $this->rejectPreparation('sender') ? false : parent::setSender($from,$name); }
    public function addRecipient($recipient, $name = '') { return $this->rejectPreparation('to') ? false : parent::addRecipient($recipient,$name); }
    public function addCc($recipient, $name = '') { return $this->rejectPreparation('cc') ? false : parent::addCc($recipient,$name); }
    public function addBcc($recipient, $name = '') { return $this->rejectPreparation('bcc') ? false : parent::addBcc($recipient,$name); }
    public function addReplyTo($address, $name = '') { return $this->rejectPreparation('reply') ? false : parent::addReplyTo($address,$name); }
    public function setSubject($subject) { return $this->rejectPreparation('subject') ? false : parent::setSubject($subject); }
    public function setBody($content) { return $this->rejectPreparation('body') ? false : parent::setBody($content); }
    public function addStringAttachment($string, $filename, $encoding = 'base64', $type = '', $disposition = 'attachment') { return $this->rejectPreparation('attachment') ? false : parent::addStringAttachment($string,$filename,$encoding,$type,$disposition); }
    public function Send()
    {
        $this->sendCalls++;
        if ($this->outcome === 'disabled') { throw new Joomla\CMS\Mail\Exception\MailDisabledException('Synthetic disabled transport'); }
        if ($this->outcome === 'exception') { throw new RuntimeException('Synthetic private transport detail'); }
        if ($this->outcome === 'false') { return false; }
        $this->isSMTP(); // Controls MIME header semantics only; preSend never opens a connection.
        if (!$this->preSend()) { throw new RuntimeException('MIME preparation failed.'); }
        $this->prepared = $this->getSentMIMEMessage();
        return true;
    }
    public function postSend() { throw new LogicException('Network delivery is prohibited in this fixture.'); }
}

final class PreparedJoomlaMailFactory implements Joomla\CMS\Mail\MailerFactoryInterface
{
    public array $messages = [];
    public string $outcome = 'prepared';
    public function createMailer(?Joomla\Registry\Registry $settings = null): Joomla\CMS\Mail\MailerInterface
    {
        if ($this->outcome === 'factory.throw') { throw new RuntimeException('Synthetic private factory detail'); }
        $mail = new PreparedJoomlaMail(); $mail->outcome = $this->outcome;
        $this->messages[] = $mail;
        return $mail;
    }
}

test('Joomla mail transport prepares isolated plain and alternative MIME messages without delivery', function (): void {
    $factory = new PreparedJoomlaMailFactory();
    $transport = new Nicode\FormStudio\Infrastructure\Joomla\MailTransport($factory, 'sender@example.test', 'Formulario á');
    $transport->send(new Nicode\FormStudio\Actions\MailMessage(['to@example.test'], ['cc@example.test'], ['private@example.test'], 'reply@example.test', 'Respuesta á', "Plain answer\nBcc: body-only@example.test", '<p>HTML answer</p>'));
    $mail = $factory->messages[0];
    same('sender@example.test', $mail->From); same('Formulario á', $mail->FromName);
    same([['to@example.test', '']], $mail->getToAddresses());
    same([['cc@example.test', '']], $mail->getCcAddresses());
    same([['private@example.test', '']], $mail->getBccAddresses());
    same(['reply@example.test' => ['reply@example.test', '']], $mail->getReplyToAddresses());
    same('Respuesta á', $mail->Subject);
    same("Plain answer\nBcc: body-only@example.test", $mail->AltBody);
    same('<p>HTML answer</p>', $mail->Body);
    [$headers] = preg_split('/\r?\n\r?\n/', $mail->prepared, 2);
    same(false, str_contains($headers, 'private@example.test'));
    same(false, str_contains($headers, 'body-only@example.test'));
    same(true, str_contains($mail->prepared, 'multipart/alternative'));
    same(true, str_contains($mail->prepared, 'text/plain'));
    same(true, str_contains($mail->prepared, 'text/html'));
    $transport->send(new Nicode\FormStudio\Actions\MailMessage(['second@example.test'], [], [], null, 'Second', 'Plain only'));
    $second = $factory->messages[1];
    same([], $second->getCcAddresses()); same([], $second->getBccAddresses()); same([], $second->getReplyToAddresses());
    same('', $second->AltBody); same('text/plain', $second->ContentType);
    same(false, str_contains($second->prepared, 'HTML answer'));
});

test('mail headers reject control injection before any Joomla mailer is created', function (): void {
    $factory = new PreparedJoomlaMailFactory();
    foreach (["\r\nBcc: victim@example.test", "\n", "\r", "\0", "\t", "\x7f"] as $control) {
        raises(InvalidArgumentException::class, fn () => new Nicode\FormStudio\Infrastructure\Joomla\MailTransport($factory, 'sender@example.test'.$control, 'Sender'));
        raises(InvalidArgumentException::class, fn () => new Nicode\FormStudio\Infrastructure\Joomla\MailTransport($factory, 'sender@example.test', 'Sender'.$control));
        foreach (['to', 'cc', 'bcc', 'reply', 'subject'] as $target) {
            $to=['to@example.test']; $cc=[]; $bcc=[]; $reply=null; $subject='Subject';
            match ($target) {
                'to' => $to=['to@example.test'.$control], 'cc' => $cc=['cc@example.test'.$control],
                'bcc' => $bcc=['bcc@example.test'.$control], 'reply' => $reply='reply@example.test'.$control,
                'subject' => $subject.=$control,
            };
            raises(InvalidArgumentException::class, fn () => new Nicode\FormStudio\Actions\MailMessage($to,$cc,$bcc,$reply,$subject,'Safe body'));
        }
        raises(InvalidArgumentException::class, fn () => (new Nicode\FormStudio\Actions\TokenTemplate())->render('Subject {{answer}}',['answer'=>'text'.$control],'header'));
    }
    same([], $factory->messages);
});

test('Joomla mail delivery failures expose stable codes and distinguish disabled from unknown delivery', function (): void {
    $factory = new PreparedJoomlaMailFactory();
    $transport = new Nicode\FormStudio\Infrastructure\Joomla\MailTransport($factory,'sender@example.test','Sender');
    $message = new Nicode\FormStudio\Actions\MailMessage(['to@example.test'],[],[],null,'Subject','Body');
    foreach (['disabled','false','exception'] as $outcome) {
        $factory->outcome=$outcome;
        try { $transport->send($message); throw new RuntimeException('Expected transport failure.'); }
        catch (Nicode\FormStudio\Actions\ActionFailure $failure) {
            same($outcome==='disabled'?'mail_disabled':'mail_delivery_unknown',$failure->resultCode);
            same($outcome!=='disabled',$failure->unknownOutcome);
            same($failure->resultCode,$failure->getMessage());
        }
    }
});

test('Joomla preparation failures never send partial messages and remain definite failures', function (): void {
    $factory=new PreparedJoomlaMailFactory();
    $transport=new Nicode\FormStudio\Infrastructure\Joomla\MailTransport($factory,'sender@example.test','Sender');
    $message=new Nicode\FormStudio\Actions\MailMessage(['to@example.test'],['cc@example.test'],['bcc@example.test'],'reply@example.test','Subject','Body','<p>Body</p>');
    $outcomes=['factory.throw'];
    foreach(['sender','to','cc','bcc','reply','subject','body'] as $stage) { $outcomes[]=$stage.'.false'; $outcomes[]=$stage.'.throw'; }
    foreach($outcomes as $outcome) {
        $factory->outcome=$outcome;
        try { $transport->send($message); throw new RuntimeException('Preparation failure was ignored: '.$outcome); }
        catch(Nicode\FormStudio\Actions\ActionFailure $failure) { same('mail_preparation_failed',$failure->resultCode); same(false,$failure->unknownOutcome); same('mail_preparation_failed',$failure->getMessage()); }
    }
    foreach($factory->messages as $mail) { same(0,$mail->sendCalls); same('',$mail->prepared); }
    $factory->outcome='prepared';
    $transport->send(new Nicode\FormStudio\Actions\MailMessage(['to@example.test','TO@example.test'],['to@example.test','cc@example.test'],['CC@example.test','bcc@example.test'],'to@example.test','Duplicates','Body'));
    $mail=$factory->messages[array_key_last($factory->messages)];
    same([['to@example.test','']],$mail->getToAddresses()); same([['cc@example.test','']],$mail->getCcAddresses()); same([['bcc@example.test','']],$mail->getBccAddresses()); same(1,$mail->sendCalls);
});

test('Joomla attachments compose actual MIME from bytes without opening file paths or leaking across messages', function (): void {
    $factory=new PreparedJoomlaMailFactory();
    $transport=new Nicode\FormStudio\Infrastructure\Joomla\MailTransport($factory,'sender@example.test','Sender');
    $bytes="Synthetic attachment\0binary\xff";
    $attachment=new Nicode\FormStudio\Actions\MailAttachment('evidence.bin','application/octet-stream',$bytes);
    $message=new Nicode\FormStudio\Actions\MailMessage(['to@example.test'],[],[],null,'Subject','Body','<p>Body</p>',[$attachment]);
    $transport->send($message); $mail=$factory->messages[0];
    same(1,count($mail->getAttachments()));
    same($bytes,$mail->getAttachments()[0][0]); same(true,$mail->getAttachments()[0][5]);
    same(true,str_contains($mail->prepared,'multipart/mixed'));
    same(true,str_contains($mail->prepared,'multipart/alternative'));
    same(true,str_contains($mail->prepared,base64_encode($bytes)));
    same(true,str_contains($mail->prepared,'Content-Disposition: attachment; filename=evidence.bin'));
    foreach(['attachment.false','attachment.throw'] as $outcome) {
        $factory->outcome=$outcome;
        try { $transport->send($message); throw new RuntimeException('Attachment rejection ignored.'); }
        catch(Nicode\FormStudio\Actions\ActionFailure $failure) { same('mail_preparation_failed',$failure->resultCode); same(false,$failure->unknownOutcome); }
        same(0,$factory->messages[array_key_last($factory->messages)]->sendCalls);
    }
    $factory->outcome='prepared';
    $transport->send(new Nicode\FormStudio\Actions\MailMessage(['to@example.test'],[],[],null,'Subject','No attachment'));
    same([],$factory->messages[array_key_last($factory->messages)]->getAttachments());
});


test('guided email tokens deliver selected fields and full summaries as plain or multipart MIME', function (): void {
    $draft = definition(); $draft['fields'][0]['type']='email'; $field=$draft['fields'][0]['uuid'];
    $factory = new PreparedJoomlaMailFactory();
    $transport = new Nicode\FormStudio\Infrastructure\Joomla\MailTransport($factory,'sender@example.test','Forms');
    $context = new Nicode\FormStudio\Actions\ActionContext(compiler()->compile($draft)->spec,[$field=>'visitor@example.test'],'reference','date');
    foreach ([false,true] as $autoresponse) {
        $action = new Nicode\FormStudio\Actions\EmailAction($transport,new Nicode\FormStudio\Actions\TokenTemplate(),$autoresponse);
        $recipient = $autoresponse ? ['email_field'=>$field] : ['to'=>['team@example.test']];
        $action->execute($recipient + ['subject'=>'Responses','email_format'=>'html','body_html'=>'<h2>Answers</h2><pre>{{field.'.$field.'.label}}: {{field.'.$field.'.option_label}}</pre><pre>{{response.summary}}</pre>'], $context);
        $mail=$factory->messages[array_key_last($factory->messages)];
        same(true,str_contains($mail->prepared,'multipart/alternative'));
        same(true,str_contains($mail->Body,'visitor@example.test')); same(false,str_contains($mail->Body,'{{'));
        same(true,str_contains($mail->AltBody,'visitor@example.test')); same(false,str_contains($mail->AltBody,'<h2>'));
        $action->execute($recipient + ['subject'=>'Responses','email_format'=>'text','body_text'=>'{{response.summary}}','body_html'=>'<p>Stored but not sent</p>'], $context);
        $mail=$factory->messages[array_key_last($factory->messages)];
        same(false,str_contains($mail->prepared,'multipart/alternative')); same(false,str_contains($mail->prepared,'Stored but not sent'));
        same(true,str_contains($mail->Body,'visitor@example.test'));
    }
});
