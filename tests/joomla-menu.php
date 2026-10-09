<?php
declare(strict_types=1);
$_SERVER['HTTP_HOST'] = '127.0.0.1:13371'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
require __DIR__ . '/joomla-config.php';
$fixture = json_decode(file_get_contents($root . '/build/joomla-runtime-results.json'), true, 512, JSON_THROW_ON_ERROR);
$db = new Nicode\FormStudio\Infrastructure\Database\Connection($container->get(Joomla\Database\DatabaseInterface::class));
$existing = $db->row('SELECT id FROM ' . $db->quote('#__menu') . " WHERE alias = 'formstudio-native-menu-acceptance' AND client_id = 0");
$extension = $db->row('SELECT extension_id FROM ' . $db->quote('#__extensions') . " WHERE element = 'com_nicode_form_studio' AND type = 'component'");
$model = $app->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator', ['ignore_request' => true]);
$data = ['id' => (int) ($existing['id'] ?? 0), 'menutype' => 'mainmenu', 'title' => 'FormStudio menu acceptance', 'alias' => 'formstudio-native-menu-acceptance', 'link' => 'index.php?option=com_nicode_form_studio&view=form&id=' . (int) $fixture['form_id'], 'type' => 'component', 'published' => 1, 'parent_id' => 1, 'component_id' => (int) $extension['extension_id'], 'access' => 1, 'language' => '*', 'home' => 0, 'params' => [], 'menuordering' => -2];
if (!$model->save($data)) { throw new RuntimeException('Native menu model could not save its isolated fixture.'); }
$id = (int) $model->getState('item.id');
if ($id < 1) { throw new RuntimeException('Native menu identity unavailable.'); }
file_put_contents($root . '/build/native-menu-fixture.json', json_encode(['menu_id' => $id, 'form_id' => (int) $fixture['form_id']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Native Joomla menu item saved through the administrator model.\n";
