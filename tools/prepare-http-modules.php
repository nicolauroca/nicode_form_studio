<?php
declare(strict_types=1);

$root = dirname(__DIR__); $site = $root . '/build/joomla-6.0.0';
define('_JEXEC', 1); define('JPATH_BASE', $site);
require $site . '/includes/defines.php'; require $site . '/includes/framework.php';
$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')->alias(Joomla\CMS\Session\Session::class, 'session.cli')->alias(Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(Joomla\Console\Application::class); Joomla\CMS\Factory::$application = $app; $app->createExtensionNamespaceMap();
if ($app->get('db') !== 'formstudio_joomla' || $app->get('host') !== '127.0.0.1:13367') { throw new RuntimeException('Refusing non-isolated module fixtures.'); }
$fixture = json_decode(file_get_contents($root . '/build/joomla-runtime-results.json'), true, 512, JSON_THROW_ON_ERROR);
$database = $container->get(Joomla\Database\DatabaseInterface::class); $events = $container->get(Joomla\Event\DispatcherInterface::class); $ids = [];
foreach (['A', 'B'] as $suffix) {
    $title = 'FormStudio isolated module ' . $suffix;
    $query = $database->createQuery()->select('id')->from('#__modules')->where('module = ' . $database->quote('mod_nicode_form_studio'))->where('title = ' . $database->quote($title));
    $database->setQuery($query); $existing = (int) $database->loadResult();
    $module = new Joomla\CMS\Table\Module($database, $events);
    if ($existing > 0) { $module->load($existing); }
    $module->title = $title; $module->module = 'mod_nicode_form_studio'; $module->position = 'bottom-a';
    $module->published = 1; $module->access = 1; $module->showtitle = 1; $module->client_id = 0; $module->language = '*'; $module->ordering = $suffix === 'A' ? 1 : 2;
    $module->params = json_encode(['form_id' => $fixture['form_id'], 'cache' => 0], JSON_THROW_ON_ERROR);
    if (!$module->check() || !$module->store()) { throw new RuntimeException('Unable to prepare native module.'); }
    $id = (int) $module->id; $ids[] = $id;
    $database->setQuery('DELETE FROM #__modules_menu WHERE moduleid = ' . $id)->execute();
    $database->setQuery('INSERT INTO #__modules_menu (moduleid, menuid) VALUES (' . $id . ', 0)')->execute();
}
file_put_contents($root . '/build/http-module-fixtures.json', json_encode(['module_ids' => $ids, 'form_id' => $fixture['form_id']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Two native modules prepared on the isolated Joomla site.\n";
