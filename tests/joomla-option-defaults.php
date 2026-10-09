<?php
declare(strict_types=1);
$_SERVER['HTTP_HOST'] = '127.0.0.1:13371'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
require __DIR__ . '/joomla-config.php';
$remote = in_array('--remote', $argv, true);
$app->loadDocument(new Joomla\CMS\Document\HtmlDocument());
$runtime = new Joomla\DI\Container($container);
$runtime->registerServiceProvider(new Nicode\FormStudio\Infrastructure\Joomla\RuntimeProvider($app, new Joomla\Registry\Registry(['captcha_mode' => 'none']), $site));
$administration = $runtime->get(Nicode\FormStudio\Application\FormAdministration::class);
$form = $administration->create('Readonly option defaults', 'option-defaults-' . bin2hex(random_bytes(6)), (int) $admin->id);
$draft = $administration->edit($form, (int) $admin->id)['draft'];
$parent = Nicode\FormStudio\Domain\Uuid::create();
$draft['elements'][] = ['uuid' => $parent, 'type' => 'field', 'parent_uuid' => null];
$draft['fields'][] = ['uuid' => $parent, 'type' => 'text', 'name' => 'parent', 'config' => ['label' => 'Parent choice', 'default' => $remote ? 'ES' : 'one']];
foreach (['select', 'multiselect'] as $type) {
    $uuid = Nicode\FormStudio\Domain\Uuid::create();
    $draft['elements'][] = ['uuid' => $uuid, 'type' => 'field', 'parent_uuid' => null];
    $draft['fields'][] = ['uuid' => $uuid, 'type' => $type, 'name' => $type, 'config' => ['label' => 'Readonly ' . $type, 'readonly' => true], 'source' => ['type' => 'static', 'dependencies' => [$parent], 'config' => ['options' => [
        ['value' => 'a', 'label' => 'First', 'default' => true, 'when' => [$parent => 'one']],
        ['value' => 'b', 'label' => 'Second', 'default' => true, 'when' => [$parent => 'two']],
        ['value' => 'c', 'label' => 'Third', 'default' => true, 'when' => [$parent => 'two']],
    ]]]];
    if ($remote) { $draft['fields'][array_key_last($draft['fields'])]['source'] = ['type' => 'fixture.department', 'dependencies' => [$parent], 'config' => ['prefix' => 'Test', 'country' => $parent, 'defaults' => true]]; }
}
$revision = $administration->save($form, 0, $draft, (int) $admin->id);
$administration->publish($form, $revision, (int) $admin->id);
echo 'Published browser fixture: http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id=' . $form . "\n";
