<?php
declare(strict_types=1);

// Resolve a native site menu destination from an actual console application.
$root = dirname(__DIR__); $site = $root . '/build/joomla-6.0.0';
$_SERVER['HTTP_HOST'] = '127.0.0.1:13371'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
define('_JEXEC', 1); define('JPATH_BASE', $site);
require $site . '/includes/defines.php'; require $site . '/includes/framework.php';
$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')->alias(Joomla\CMS\Session\Session::class, 'session.cli')->alias(Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(Joomla\CMS\Application\ConsoleApplication::class); Joomla\CMS\Factory::$application = $app;
if ($app->get('db') !== 'formstudio_joomla' || $app->get('host') !== '127.0.0.1:13367') { throw new RuntimeException('Refusing non-isolated console navigation test.'); }
$app->createExtensionNamespaceMap();
$credentials = json_decode(file_get_contents($root . '/build/joomla-test.json'), true, 512, JSON_THROW_ON_ERROR);
$app->loadIdentity($container->get(Joomla\CMS\User\UserFactoryInterface::class)->loadUserByUsername($credentials['username'])); unset($credentials);
$runtime = $app->bootComponent('com_nicode_form_studio')->runtime($app);
$fixture = json_decode(file_get_contents($root . '/build/native-menu-fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$action = $runtime->get(Nicode\FormStudio\Registry\ActionRegistry::class)->get('redirect');
if ($action->validateConfiguration(['menu_id' => $fixture['menu_id']], '/navigation') !== []) { throw new RuntimeException('Native console menu validation failed.'); }
$forms = $runtime->get(Nicode\FormStudio\Infrastructure\Database\FormRepository::class); $form = $forms->get($fixture['form_id']);
$context = new Nicode\FormStudio\Actions\ActionContext($forms->version($fixture['form_id'], (int) $form['published_version_id']), [], 'console-navigation-fixture', gmdate(DATE_ATOM));
$outcome = $action->execute(['menu_id' => $fixture['menu_id']], $context);
if ($outcome->code !== 'navigation_selected' || str_contains($outcome->navigation['redirect'] ?? '', '/administrator/') || !str_contains($outcome->navigation['redirect'] ?? '', 'formstudio-native-menu-acceptance')) { throw new RuntimeException('Console navigation did not use the native site router.'); }
if ($action->validateConfiguration(['menu_id' => PHP_INT_MAX], '/navigation') === []) { throw new RuntimeException('Missing menu destination was accepted.'); }
echo "Native console navigation: installed menu factory, site router and missing destination rejection passed.\n";
