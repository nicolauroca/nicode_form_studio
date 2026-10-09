<?php
declare(strict_types=1);
// Creates only synthetic plugin/form data in the explicitly named local HTTP test site.
$root = dirname(__DIR__); $site = $root . '/build/joomla-6.0.0';
require $site . '/configuration.php'; $configuration = new JConfig();
if ($configuration->db !== 'formstudio_joomla' || $configuration->dbprefix !== 'j6_' || $configuration->host !== '127.0.0.1:13367') { throw new RuntimeException('Refusing non-isolated dynamic options fixture.'); }
$zipPath = $root . '/build/provider-http-fixture.zip'; $zip = new ZipArchive(); $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$fixture = $root . '/tests/fixtures/plg_formstudio_providerfixture';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile()) { $zip->addFile($file->getPathname(), str_replace('\\', '/', substr($file->getPathname(), strlen($fixture) + 1))); }
}
$zip->close(); $output = []; $code = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($site . '/cli/joomla.php') . ' extension:install --path=' . escapeshellarg($zipPath) . ' 2>&1', $output, $code);
if ($code !== 0) { throw new RuntimeException('Native fixture plugin installation failed: ' . implode("\n", $output)); }
$pdo = new PDO('mysql:host=127.0.0.1;port=13367;dbname=formstudio_joomla;charset=utf8mb4', $configuration->user, $configuration->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("UPDATE j6_extensions SET enabled = 1 WHERE type = 'plugin' AND folder = 'formstudio' AND element = 'providerfixture'");
$_SERVER['HTTP_HOST'] = '127.0.0.1:13371'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
define('_JEXEC', 1); define('JPATH_BASE', $site);
require $site . '/includes/defines.php'; require $site . '/includes/framework.php';
$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')->alias(Joomla\CMS\Session\Session::class, 'session.cli')->alias(Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(Joomla\CMS\Application\SiteApplication::class); Joomla\CMS\Factory::$application = $app;
$app->createExtensionNamespaceMap(); $app->loadLanguage($container->get(Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('en-GB', false));
$credentials = json_decode(file_get_contents($root . '/build/joomla-test.json'), true, 512, JSON_THROW_ON_ERROR);
$admin = $container->get(Joomla\CMS\User\UserFactoryInterface::class)->loadUserByUsername($credentials['username']); unset($credentials); $app->loadIdentity($admin);
$runtime = $app->bootComponent('com_nicode_form_studio')->runtime($app); $forms = $runtime->get(Nicode\FormStudio\Application\FormAdministration::class);
$form = $forms->create('Dynamic provider browser fixture', 'dynamic-http-' . bin2hex(random_bytes(5)), (int) $admin->id);
$draft = $forms->edit($form, (int) $admin->id)['draft']; $country = Nicode\FormStudio\Domain\Uuid::create();
$draft['elements'] = [['uuid' => $country, 'type' => 'field', 'parent_uuid' => null]];
$draft['fields'] = [['uuid' => $country, 'name' => 'country', 'type' => 'select', 'config' => ['label' => 'Country', 'default' => 'ES'], 'options' => [['value' => 'ES', 'label' => 'Spain'], ['value' => 'FR', 'label' => 'France']]]];
$identities = ['country' => $country];
foreach (['select' => 'Province', 'radio' => 'Office', 'checkbox-group' => 'Service areas'] as $type => $label) {
    $uuid = Nicode\FormStudio\Domain\Uuid::create(); $identities[$type] = $uuid;
    $draft['elements'][] = ['uuid' => $uuid, 'type' => 'field', 'parent_uuid' => null];
    $draft['fields'][] = ['uuid' => $uuid, 'name' => str_replace('-', '_', $type), 'type' => $type, 'config' => ['label' => $label], 'source' => ['type' => 'fixture.department', 'dependencies' => [$country], 'config' => ['prefix' => 'Fixture', 'country' => $country, 'private_marker' => 'not-public']]];
}
$revision = $forms->save($form, 0, $draft, (int) $admin->id); $version = $forms->publish($form, $revision, (int) $admin->id);
file_put_contents($root . '/build/native-dynamic-options.json', json_encode(['form_id' => $form, 'version_id' => $version, 'fields' => $identities], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Native synthetic dynamic-options fixture prepared.\n";
