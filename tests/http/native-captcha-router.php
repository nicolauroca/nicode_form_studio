<?php
declare(strict_types=1);
// Test-only Joomla CAPTCHA provider, never part of an extension package.
$root = dirname(__DIR__, 2); $site = realpath($root . '/build/joomla-6.0.0'); $cli = PHP_SAPI === 'cli';
if (!$cli) {
    $auth = json_decode(file_get_contents($root . '/build/upload-test.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || !hash_equals($auth['nonce'], $_SERVER['HTTP_X_TEST_NONCE'] ?? '')) { http_response_code(403); exit; }
}
define('_JEXEC', 1); define('JPATH_BASE', $site);
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php'; $_SERVER['SCRIPT_FILENAME'] = $site . '/index.php';
$_SERVER['HTTP_HOST'] = '127.0.0.1:13371'; $_SERVER['SERVER_PORT'] = '13371'; unset($_SERVER['PATH_INFO']);
require $site . '/includes/defines.php'; require $site . '/includes/framework.php';
$container = Joomla\CMS\Factory::getContainer(); $config = $container->get('config');
if ($config->get('db') !== 'formstudio_joomla' || $config->get('host') !== '127.0.0.1:13367') { throw new RuntimeException('Refusing non-isolated CAPTCHA fixture.'); }
$session = $cli ? 'session.cli' : 'session.web.site';
$container->alias('session', $session)->alias('session.web', $session)->alias(Joomla\CMS\Session\Session::class, $session)->alias(Joomla\Session\SessionInterface::class, $session);
$container->get(Joomla\Event\DispatcherInterface::class)->addListener('onCaptchaSetup', static function ($event): void {
$event->getArgument('subject')->add(new class implements Joomla\CMS\Captcha\CaptchaProviderInterface {
    public function getName(): string { return 'fixture-native-captcha'; }
    public function display(string $name = '', array $attributes = []): string { return '<input name="formstudio_captcha" value="" aria-label="Test-only CAPTCHA answer">'; }
    public function checkAnswer(?string $code = null): bool {
        if ($code === 'outage') { throw new RuntimeException('Private CAPTCHA provider credential'); }
        return $code === 'fixture-valid';
    }
    public function setupField(Joomla\CMS\Form\FormField $field, SimpleXMLElement $element): void {}
});
});
if (!$cli) { require $site . '/includes/app.php'; return; }
$app = $container->get(Joomla\CMS\Application\SiteApplication::class); Joomla\CMS\Factory::$application = $app; $app->createExtensionNamespaceMap();
$app->loadLanguage($container->get(Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('en-GB', false));
$credentials = json_decode(file_get_contents($root . '/build/joomla-test.json'), true, flags: JSON_THROW_ON_ERROR);
$admin = $container->get(Joomla\CMS\User\UserFactoryInterface::class)->loadUserByUsername($credentials['username']); unset($credentials); $app->loadIdentity($admin);
$runtime = $app->bootComponent('com_nicode_form_studio')->runtime($app);
$forms = $runtime->get(Nicode\FormStudio\Application\FormAdministration::class);
$id = $forms->create('Native CAPTCHA messages', 'captcha-message-' . bin2hex(random_bytes(6)), (int) $admin->id);
$draft = $forms->edit($id, (int) $admin->id)['draft']; $field = Nicode\FormStudio\Domain\Uuid::create();
$draft['elements'] = [['uuid' => $field, 'type' => 'field']];
$draft['fields'] = [['uuid' => $field, 'name' => 'answer', 'type' => 'text', 'config' => ['required' => true]]];
$draft['security']['captcha'] = ['mode' => 'provider', 'provider' => 'fixture-native-captcha'];
foreach (['captcha_error', 'captcha_unavailable'] as $category) { $draft['post_submit']['messages'][$category] = 'Configured ' . $category . ' {{form.name}} <script>captcha-marker</script>'; }
$revision = $forms->save($id, 0, $draft, (int) $admin->id); $forms->publish($id, $revision, (int) $admin->id);
file_put_contents($root . '/build/native-captcha-fixture.json', json_encode(['form_id' => $id, 'field' => $field], JSON_THROW_ON_ERROR));
echo "Isolated native CAPTCHA message form prepared.\n";
