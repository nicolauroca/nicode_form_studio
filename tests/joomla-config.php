<?php
declare(strict_types=1);
// Prepares only the named isolated Joomla fixture, with storage outside its web root.
$root = dirname(__DIR__); $site = $root . '/build/joomla-6.0.0';
define('_JEXEC', 1); define('JPATH_BASE', $site);
require $site . '/includes/defines.php'; require $site . '/includes/framework.php'; require $root . '/src/lib_nicode_form_studio/autoload.php';
$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')->alias(Joomla\CMS\Session\Session::class, 'session.cli')->alias(Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(Joomla\CMS\Application\SiteApplication::class); Joomla\CMS\Factory::$application = $app; $app->createExtensionNamespaceMap();
if ($app->get('db') !== 'formstudio_joomla' || $app->get('host') !== '127.0.0.1:13367') { throw new RuntimeException('Refusing non-isolated file fixture.'); }
$app->loadLanguage($container->get(Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('en-GB', false));
$app->loadDocument(new Joomla\CMS\Document\HtmlDocument());
$users = $container->get(Joomla\CMS\User\UserFactoryInterface::class);
$credentials = json_decode(file_get_contents($root . '/build/joomla-test.json'), true, 512, JSON_THROW_ON_ERROR); $admin = $users->loadUserByUsername($credentials['username']); unset($credentials); $app->loadIdentity($admin);
$configForm = Joomla\CMS\Form\Form::getInstance('formstudio.configuration.test', $site . '/administrator/components/com_nicode_form_studio/config.xml', ['control' => 'jform'], false, '/config');
$private = $root . '/build/native-private-exports';
foreach ([$private, ''] as $path) {
    if (!$configForm->validate(['default_persistence' => 'full', 'storage_path' => $path, 'export_path' => $path])) { foreach ($configForm->getErrors() as $error) { echo (string) $error . PHP_EOL; } throw new RuntimeException('Native private path rule rejected valid configuration.'); }
}
foreach ([$site, $site . '/images', 'relative/directory', $private . '/missing', "bad\0path"] as $path) {
    if ($configForm->validate(['default_persistence' => 'full', 'export_path' => $path])) { throw new RuntimeException('Native config accepted an unsafe or unavailable directory.'); }
}
echo "Native component configuration: namespaced path validator accepts private roots and rejects public, relative and unavailable paths.\n";
foreach ([0, -1, 3651] as $days) { if ($configForm->validate(['default_persistence' => 'full', 'technical_log_days' => $days])) { throw new RuntimeException('Native config accepted invalid technical log retention.'); } }
foreach (['audit_log_days', 'action_history_days'] as $key) {
    foreach ([0, 1, 3650] as $days) { if (!$configForm->validate(['default_persistence' => 'full', $key => $days])) { throw new RuntimeException('Native config rejected valid history retention.'); } }
    foreach ([-1, 3651] as $days) { if ($configForm->validate(['default_persistence' => 'full', $key => $days])) { throw new RuntimeException('Native config accepted invalid history retention.'); } }
}
