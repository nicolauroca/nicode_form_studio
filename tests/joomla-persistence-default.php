<?php
declare(strict_types=1);
// Prepares only the named isolated Joomla fixture, with storage outside its web root.
$root = dirname(__DIR__); $site = $root . '/build/joomla-6.0.0';
$_SERVER['HTTP_HOST'] = '127.0.0.1:13371'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['PHP_SELF'] = '/index.php';
define('_JEXEC', 1); define('JPATH_BASE', $site);
require $site . '/includes/defines.php'; require $site . '/includes/framework.php'; require $root . '/src/lib_nicode_form_studio/autoload.php';
$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')->alias(Joomla\CMS\Session\Session::class, 'session.cli')->alias(Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(Joomla\CMS\Application\SiteApplication::class); Joomla\CMS\Factory::$application = $app; $app->createExtensionNamespaceMap();
if ($app->get('db') !== 'formstudio_joomla' || $app->get('host') !== '127.0.0.1:13367') { throw new RuntimeException('Refusing non-isolated file fixture.'); }
$app->loadLanguage($container->get(Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('en-GB', false));
$users = $container->get(Joomla\CMS\User\UserFactoryInterface::class);
$credentials = json_decode(file_get_contents($root . '/build/joomla-test.json'), true, 512, JSON_THROW_ON_ERROR); $admin = $users->loadUserByUsername($credentials['username']); unset($credentials); $app->loadIdentity($admin);
$db = new Nicode\FormStudio\Infrastructure\Database\Connection($container->get(Joomla\Database\DatabaseInterface::class));
$extension = $db->row('SELECT params FROM ' . $db->quote('#__extensions') . " WHERE type='component' AND element='com_nicode_form_studio'");
$xml = simplexml_load_file($site . '/administrator/components/com_nicode_form_studio/config.xml');
$field = $xml->xpath('//field[@name="default_persistence"]')[0];
$rule = new Joomla\CMS\Form\Rule\OptionsRule(); $created = [];
foreach (['full', 'metadata', 'none'] as $mode) {
    if (!$rule->test($field, $mode)) { throw new RuntimeException('Native options rule rejected a supported storage mode.'); }
    $params = new Joomla\Registry\Registry($extension['params']); $params->set('default_persistence', $mode);
    $runtime = new Joomla\DI\Container($container);
    $runtime->registerServiceProvider(new Nicode\FormStudio\Infrastructure\Joomla\RuntimeProvider($app, $params, $site));
    $administration = $runtime->get(Nicode\FormStudio\Application\FormAdministration::class);
    $form = $administration->create('Native storage default', 'native-storage-default-' . bin2hex(random_bytes(6)), (int) $admin->id);
    $draft = $administration->edit($form, (int) $admin->id)['draft'];
    if (($draft['persistence']['mode'] ?? null) !== $mode) { throw new RuntimeException('Native runtime composition lost configured persistence mode.'); }
    foreach ($created as [$id, $original]) {
        if ($administration->edit($id, (int) $admin->id)['draft']['persistence']['mode'] !== $original) { throw new RuntimeException('Another runtime default rewrote existing form policy.'); }
    }
    $created[] = [$form, $mode];
}
foreach (['invalid', '', null] as $invalid) { if ($rule->test($field, $invalid)) { throw new RuntimeException('Native option validator accepted missing or unknown mode.'); } }
if ($db->row('SELECT params FROM ' . $db->quote('#__extensions') . " WHERE type='component' AND element='com_nicode_form_studio'")['params'] !== $extension['params']) { throw new RuntimeException('Fixture changed installed global configuration.'); }
file_put_contents($root . '/build/native-persistence-default-results.json', json_encode(['passed' => true, 'forms' => $created, 'configuration_unchanged' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Native persistence composition: three configured defaults, stable existing drafts and native option validation passed without changing site configuration.\n";
