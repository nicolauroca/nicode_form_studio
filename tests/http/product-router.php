<?php
declare(strict_types=1);

// Loopback/nonce-gated native Joomla composition for the complete product scenario.
$root = dirname(__DIR__, 2); $site = realpath($root . '/build/joomla-6.0.0');
$auth = json_decode(file_get_contents($root . '/build/upload-test.json'), true, flags: JSON_THROW_ON_ERROR);
if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || !hash_equals($auth['nonce'], $_SERVER['HTTP_X_TEST_NONCE'] ?? '')) { http_response_code(403); exit; }
$administrator = str_starts_with($_SERVER['REQUEST_URI'], '/administrator/index.php');
$applicationRoot = $administrator ? realpath($site . '/administrator') : $site;
define('_JEXEC', 1); define('JPATH_BASE', $applicationRoot);
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = ($administrator ? '/administrator' : '') . '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $applicationRoot . '/index.php'; unset($_SERVER['PATH_INFO']);
$_SERVER['HTTP_HOST'] = '127.0.0.1:13371'; $_SERVER['SERVER_PORT'] = '13371';
require $applicationRoot . '/includes/defines.php'; require $applicationRoot . '/includes/framework.php';
$container = Joomla\CMS\Factory::getContainer(); $config = $container->get('config');
if ($config->get('db') !== 'formstudio_joomla' || $config->get('host') !== '127.0.0.1:13367') { throw new RuntimeException('Non-isolated product fixture.'); }
$session = $administrator ? 'session.web.administrator' : 'session.web.site';
$container->alias('session.web', $session)->alias('session', $session)->alias(Joomla\CMS\Session\Session::class, $session)->alias(Joomla\Session\SessionInterface::class, $session);
$container->get(Joomla\Event\DispatcherInterface::class)->addListener('onCaptchaSetup', static function ($event): void {
    $event->getArgument('subject')->add(new class implements Joomla\CMS\Captcha\CaptchaProviderInterface {
        public function getName(): string { return 'fixture-product-captcha'; }
        public function display(string $name = '', array $attributes = []): string { return '<input name="formstudio_captcha" aria-label="Synthetic CAPTCHA">'; }
        public function checkAnswer(?string $code = null): bool { return $code === 'fixture-valid'; }
        public function setupField(Joomla\CMS\Form\FormField $field, SimpleXMLElement $element): void {}
    });
});
foreach (['mailonline' => true, 'mailfrom' => 'fixture@example.test', 'fromname' => 'Product acceptance'] as $key => $value) { $config->set($key, $value); }
$container->alias(Joomla\CMS\Mail\MailerFactoryInterface::class, 'fixture.product.mail');
$container->share('fixture.product.mail', static fn () => new class($root) implements Joomla\CMS\Mail\MailerFactoryInterface {
    public function __construct(private string $root) {}
    public function createMailer(?Joomla\Registry\Registry $settings = null): Joomla\CMS\Mail\MailerInterface {
        return new class($this->root) extends Joomla\CMS\Mail\Mail {
            public function __construct(private string $root) { parent::__construct(true); }
            public function Send() {
                $this->isSMTP();
                if (!$this->preSend()) { throw new RuntimeException('Synthetic MIME preparation failed.'); }
                if (($_SERVER['HTTP_X_TEST_MAIL_FAILURE'] ?? '') === 'disabled' && str_starts_with($this->Subject, 'Internal')) { throw new Joomla\CMS\Mail\Exception\MailDisabledException('Synthetic definite mail failure'); }
                file_put_contents($this->root . '/build/product-mail-capture.jsonl', json_encode(['to' => $this->getToAddresses(), 'subject' => $this->Subject, 'mime' => base64_encode($this->getSentMIMEMessage())], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
                return true;
            }
            public function postSend() { throw new LogicException('Product fixture cannot deliver mail.'); }
        };
    }
});
require $applicationRoot . '/includes/app.php';
