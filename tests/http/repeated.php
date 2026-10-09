<?php
declare(strict_types=1);

// Included only after index.php's loopback + nonce authorization.
require __DIR__.'/../repeated-upload-support.php';
try {
    $mail=new class implements Nicode\FormStudio\Contract\MailTransportInterface {
        public array $messages=[];
        public function send(Nicode\FormStudio\Actions\MailMessage $message):void {
            $this->messages[]=array_map(static fn($file)=>['name'=>$file->name,'mime'=>$file->mimeType,'bytes'=>base64_encode($file->bytes)],$message->attachments);
        }
    };
    $services=repeatedUploadServices(($_POST['reject_captcha']??'')==='1',$mail);
    $fixture=json_decode(file_get_contents($root.'/build/repeated-upload-test.json'),true,flags:JSON_THROW_ON_ERROR);
    if((int)($_POST['form_id']??0)!==$fixture['form'] || (int)($_POST['version_id']??0)!==$fixture['version']) { throw new InvalidArgumentException('Wrong fixture.'); }
    $spec=$services['forms']->version($fixture['form'],$fixture['version']);
    $request=Nicode\FormStudio\Infrastructure\Joomla\RequestAdapter::requestInstances(new Joomla\Input\Input(),$spec);
    $context=new Nicode\FormStudio\Submission\RequestContext(0,[1],'en-GB','multipart-fixture',hash('sha256','multipart-fixture'),true);
    echo json_encode($services['pipeline']->submitInstances($request,$context)+['fixture_messages'=>$mail->messages],JSON_THROW_ON_ERROR);
} catch(Throwable $error) { error_log('Repeated fixture: '.get_class($error).' '.$error->getFile().':'.$error->getLine()); http_response_code(422); echo '{"accepted":false,"category":"fixture_request_rejected"}'; }
