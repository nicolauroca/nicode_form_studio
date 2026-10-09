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
$users = $container->get(Joomla\CMS\User\UserFactoryInterface::class);
$credentials = json_decode(file_get_contents($root . '/build/joomla-test.json'), true, 512, JSON_THROW_ON_ERROR); $admin = $users->loadUserByUsername($credentials['username']); unset($credentials); $app->loadIdentity($admin);
$storage = $root . '/build/native-private-files'; if (!is_dir($storage)) { mkdir($storage, 0770, true); }
$db = new Nicode\FormStudio\Infrastructure\Database\Connection($container->get(Joomla\Database\DatabaseInterface::class));
$extension = $db->row('SELECT extension_id, params FROM ' . $db->quote('#__extensions') . " WHERE type = 'component' AND element = 'com_nicode_form_studio'");
$params = new Joomla\Registry\Registry($extension['params']); $params->set('storage_path', $storage);
$db->execute('UPDATE ' . $db->quote('#__extensions') . ' SET params = :params WHERE extension_id = :id', [':params' => $params->toString(), ':id' => (int) $extension['extension_id']]);
$runtime = new Joomla\DI\Container($container); $runtime->registerServiceProvider(new Nicode\FormStudio\Infrastructure\Joomla\RuntimeProvider($app, $params, $site));
$administration = $runtime->get(Nicode\FormStudio\Application\FormAdministration::class);
$form = $administration->create('Native private file fixture', 'native-file-' . bin2hex(random_bytes(5)), (int) $admin->id);
$draft = $administration->edit($form, (int) $admin->id)['draft']; $field = Nicode\FormStudio\Domain\Uuid::create();
$draft['elements'] = [['uuid' => $field, 'type' => 'field']];
$draft['fields'] = [['uuid' => $field, 'name' => 'attachment', 'type' => 'file', 'sensitive' => true, 'config' => ['label' => 'Restricted attachment', 'required' => true, 'extensions' => ['txt'], 'mime_types' => ['text/plain'], 'max_bytes' => 4096]]];
$draft['post_submit']['messages']['upload_error'] = 'Choose an allowed file for {{form.name}} <script>upload-message</script>';
$multiple = in_array('--multiple', $argv, true);
if ($multiple) { $draft['fields'][0]['type'] = 'multiple-files'; $draft['fields'][0]['config']['max_files'] = 2; }
$revision = $administration->save($form, 0, $draft, (int) $admin->id); $version = $administration->publish($form, $revision, (int) $admin->id);
file_put_contents($root . '/build/native-file' . ($multiple ? '-multiple' : '') . '-fixture.json', json_encode(['form_id' => $form, 'version_id' => $version, 'field_uuid' => $field], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Isolated native file form and private storage configured.\n";
