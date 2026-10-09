<?php
declare(strict_types=1);

$root = dirname(__DIR__); $site = $root . '/build/joomla-6.0.0';
$_SERVER['HTTP_HOST'] = '127.0.0.1:13371'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
define('_JEXEC', 1); define('JPATH_BASE', $site);
require $site . '/includes/defines.php'; require $site . '/includes/framework.php'; require $site . '/libraries/nicode_form_studio/autoload.php';
$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')->alias(Joomla\CMS\Session\Session::class, 'session.cli')->alias(Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(Joomla\CMS\Application\SiteApplication::class); Joomla\CMS\Factory::$application = $app; $app->createExtensionNamespaceMap();
if ($app->get('db') !== 'formstudio_joomla' || $app->get('host') !== '127.0.0.1:13367') { throw new RuntimeException('Non-isolated layout fixture.'); }
$credentials = json_decode(file_get_contents($root . '/build/joomla-test.json'), true, flags: JSON_THROW_ON_ERROR);
$admin = $container->get(Joomla\CMS\User\UserFactoryInterface::class)->loadUserByUsername($credentials['username']); unset($credentials); $app->loadIdentity($admin);
$app->loadLanguage($container->get(Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('en-GB', false));
$driver = $container->get(Joomla\Database\DatabaseInterface::class); $db = new Nicode\FormStudio\Infrastructure\Database\Connection($driver);
$form = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT); $record = $db->row('SELECT alias FROM ' . $db->table('forms') . ' WHERE id = :id', [':id' => $form]);
if (!$form || !$record || !str_starts_with($record['alias'], 'product-acceptance-')) { throw new RuntimeException('Expected own product form.'); }
$component = $db->row('SELECT extension_id FROM ' . $db->quote('#__extensions') . " WHERE type = 'component' AND element = 'com_nicode_form_studio'");
$model = $app->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator', ['ignore_request' => true]);
if (!$model->save(['id' => 0, 'menutype' => 'mainmenu', 'title' => 'Product acceptance', 'alias' => $record['alias'], 'link' => 'index.php?option=com_nicode_form_studio&view=form&id=' . $form, 'type' => 'component', 'published' => 1, 'parent_id' => 1, 'component_id' => (int) $component['extension_id'], 'access' => 1, 'language' => '*', 'home' => 0, 'params' => [], 'menuordering' => -2])) { throw new RuntimeException('Menu fixture failed.'); }
$menu = (int) $model->getState('item.id'); $modules = [];
foreach ([1, 2] as $ordinal) {
    $module = new Joomla\CMS\Table\Module($driver, $container->get(Joomla\Event\DispatcherInterface::class));
    $module->note = ''; $module->content = ''; $module->title = 'Product instance ' . $ordinal; $module->module = 'mod_nicode_form_studio'; $module->position = 'bottom-a'; $module->published = 1; $module->access = 1; $module->showtitle = 1; $module->client_id = 0; $module->language = '*'; $module->ordering = 100 + $ordinal;
    $module->params = json_encode(['form_id' => $form, 'cache' => 0, 'unavailable_mode' => 'hide'], JSON_THROW_ON_ERROR);
    if (!$module->check() || !$module->store()) { throw new RuntimeException('Module fixture failed.'); }
    $modules[] = (int) $module->id;
    $db->execute('INSERT INTO ' . $db->quote('#__modules_menu') . ' (moduleid, menuid) VALUES (:module, :menu)', [':module' => (int) $module->id, ':menu' => $menu]);
}
file_put_contents($root . '/build/product-layout.json', json_encode(['form_id' => $form, 'menu_id' => $menu, 'module_ids' => $modules], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Native menu and two module instances created.\n";
