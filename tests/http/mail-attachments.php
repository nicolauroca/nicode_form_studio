<?php
declare(strict_types=1);

// Test router authorizes loopback and its nonce before including this fixture.
require __DIR__.'/../repeated-upload-support.php';
try {
    $fixture=json_decode(file_get_contents($root.'/build/mail-upload-test.json'),true,flags:JSON_THROW_ON_ERROR);
    $mail=new class($fixture['outcome'] ?? 'success') implements Nicode\FormStudio\Contract\MailTransportInterface {
        public array $messages=[];
        public function __construct(private string $outcome) {}
        public function send(Nicode\FormStudio\Actions\MailMessage $message):void {
            $this->messages[]=array_map(static fn($file)=>['name'=>$file->name,'mime'=>$file->mimeType,'bytes'=>base64_encode($file->bytes)],$message->attachments);
            if ($this->outcome !== 'success') { throw new Nicode\FormStudio\Actions\ActionFailure('fixture_mail_failed', $this->outcome === 'unknown'); }
        }
    };
    $services=repeatedUploadServices(mail:$mail);
    if((int)($_POST['form_id']??0)!==$fixture['form'] || (int)($_POST['version_id']??0)!==$fixture['version']) { throw new InvalidArgumentException('Wrong fixture.'); }
    $spec=$services['forms']->version($fixture['form'],$fixture['version']);
    $request=Nicode\FormStudio\Infrastructure\Joomla\RequestAdapter::request(new Joomla\Input\Input(),$spec);
    $context=new Nicode\FormStudio\Submission\RequestContext(0,[1],'en-GB','multipart-fixture',hash('sha256','multipart-fixture'),true);
    $result=$services['pipeline']->submit($request,$context);
    echo json_encode(['result'=>$result,'messages'=>$mail->messages],JSON_THROW_ON_ERROR);
} catch(Throwable $error) { error_log('Mail upload fixture: '.get_class($error).' '.$error->getFile().':'.$error->getLine()); http_response_code(422); echo '{"error":"fixture_request_rejected"}'; }
