<?php
declare(strict_types=1);
// Separate loopback-only test process; never changes the site's saved mail settings.
$root=dirname(__DIR__,2); $site=realpath($root.'/build/joomla-6.0.0');
$auth=json_decode(file_get_contents($root.'/build/upload-test.json'),true,flags:JSON_THROW_ON_ERROR);
if(($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1' || !hash_equals($auth['nonce'],$_SERVER['HTTP_X_TEST_NONCE']??'')) { http_response_code(403); exit; }
define('_JEXEC',1); define('JPATH_BASE',$site);
$_SERVER['SCRIPT_NAME']='/index.php'; $_SERVER['PHP_SELF']='/index.php'; $_SERVER['SCRIPT_FILENAME']=$site.'/index.php';
unset($_SERVER['PATH_INFO']);
$_SERVER['HTTP_HOST']='127.0.0.1:13371'; $_SERVER['SERVER_PORT']='13371';
require $site.'/includes/defines.php'; require $site.'/includes/framework.php';
$parent=Joomla\CMS\Factory::getContainer();
$parent->alias('session.web','session.web.site')->alias('session','session.web.site')->alias('JSession','session.web.site')->alias(Joomla\CMS\Session\Session::class,'session.web.site')->alias(Joomla\Session\Session::class,'session.web.site')->alias(Joomla\Session\SessionInterface::class,'session.web.site');
$config=$parent->get('config');
if($config->get('db')!=='formstudio_joomla' || $config->get('host')!=='127.0.0.1:13367') { throw new RuntimeException('Non-isolated mail fixture.'); }
foreach(['mailonline'=>true,'mailfrom'=>'fixture@example.test','fromname'=>'Attachment fixture'] as $key=>$value) { $config->set($key,$value); }
$capture=$parent;
$capture->alias(Joomla\CMS\Mail\MailerFactoryInterface::class,'fixture.mailer');
$capture->share('fixture.mailer',static fn()=>new class($root) implements Joomla\CMS\Mail\MailerFactoryInterface {
    public function __construct(private string $root) {}
    public function createMailer(?Joomla\Registry\Registry $settings=null): Joomla\CMS\Mail\MailerInterface {
        return new class($this->root) extends Joomla\CMS\Mail\Mail {
            public function __construct(private string $root) { parent::__construct(true); }
            public function Send() {
                $this->isSMTP();
                if(!$this->preSend()) { throw new RuntimeException('Fixture MIME preparation failed.'); }
                file_put_contents($this->root.'/build/native-mail-capture.jsonl',json_encode(['mime'=>base64_encode($this->getSentMIMEMessage()),'to'=>$this->getToAddresses(),'cc'=>$this->getCcAddresses(),'bcc'=>$this->getBccAddresses(),'reply_to'=>$this->getReplyToAddresses(),'from'=>$this->From,'subject'=>$this->Subject,'html'=>$this->Body,'text'=>$this->AltBody],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
                if (($_SERVER['HTTP_X_TEST_MAIL_FAILURE'] ?? '') === 'disabled') { throw new Joomla\CMS\Mail\Exception\MailDisabledException('Private test transport disabled'); }
                if (($_SERVER['HTTP_X_TEST_MAIL_FAILURE'] ?? '') === 'unknown') { throw new RuntimeException('Private test transport connection lost'); }
                return true;
            }
            public function postSend() { throw new LogicException('Fixture must never deliver mail.'); }
        };
    }
});
Joomla\CMS\Factory::$container=$capture;
set_exception_handler(static function(Throwable $error):void { error_log(get_class($error).': '.$error->getMessage()); http_response_code(500); });
require $site.'/includes/app.php';
