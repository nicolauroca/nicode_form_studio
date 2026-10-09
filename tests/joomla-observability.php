<?php
declare(strict_types=1);

$root = dirname(__DIR__); $site = $root . '/build/joomla-6.0.0';
define('_JEXEC', 1); define('JPATH_BASE', $site);
require $site . '/includes/defines.php'; require $site . '/includes/framework.php';
require $root . '/src/lib_nicode_form_studio/autoload.php';
$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')->alias(Joomla\CMS\Session\Session::class, 'session.cli')->alias(Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(Joomla\CMS\Application\SiteApplication::class); Joomla\CMS\Factory::$application = $app;
$app->createExtensionNamespaceMap();
if ($app->get('db') !== 'formstudio_joomla' || $app->get('host') !== '127.0.0.1:13367') { throw new RuntimeException('Refusing non-isolated observability fixture.'); }
$app->loadIdentity($container->get(Joomla\CMS\User\UserFactoryInterface::class)->loadUserById(0));
$runtime = new Joomla\DI\Container($container);
$runtime->registerServiceProvider(new Nicode\FormStudio\Infrastructure\Joomla\RuntimeProvider($app, new Joomla\Registry\Registry(['storage_path' => '', 'captcha_mode' => 'none']), $site));
$db = $runtime->get(Nicode\FormStudio\Infrastructure\Database\Connection::class);
$before = (int) ($db->row('SELECT MAX(id) AS id FROM ' . $db->table('technical_log'))['id'] ?? 0);
$storage = $runtime->get(Nicode\FormStudio\Registry\StorageProviderRegistry::class);
if (($storage->get('local')->metadata()['available'] ?? true) !== false) { throw new RuntimeException('Invalid storage did not fail closed.'); }
$sources = new Nicode\FormStudio\Registry\DataSourceRegistry();
$runtime->set(Nicode\FormStudio\Registry\DataSourceRegistry::class, $sources);
$sources->register(new class implements Nicode\FormStudio\Contract\DataSourceInterface {
    public function id(): string { return 'fixture.failure'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return []; }
    public function validateConfiguration(array $configuration, string $path): array { return []; }
    public function options(array $configuration, array $inputs, array $trustedContext): array { throw new RuntimeException('PRIVATE-PROVIDER-TEXT'); }
});
try { $runtime->get(Nicode\FormStudio\DataSource\OptionResolver::class)->resolve(['type' => 'fixture.failure', 'config' => ['secret' => 'PRIVATE-CONFIG']], ['answer' => 'PRIVATE-ANSWER']); throw new LogicException('Source failure was swallowed.'); }
catch (RuntimeException $error) { if ($error->getMessage() !== 'PRIVATE-PROVIDER-TEXT') { throw $error; } }
$rows = $db->rows('SELECT * FROM ' . $db->table('technical_log') . ' WHERE id > :before ORDER BY id', [':before' => $before]);
if (array_column($rows, 'event_type') !== ['storage.unavailable', 'datasource.failed']) { throw new RuntimeException('Native failure producers not wired to technical log.'); }
foreach ($rows as $row) { if ($row['level'] !== 'ERROR' || !Nicode\FormStudio\Domain\Uuid::valid($row['correlation_id']) || str_contains(json_encode($row), 'PRIVATE-')) { throw new RuntimeException('Technical event missing safe correlation or disclosed private values.'); } }
file_put_contents($root . '/build/native-observability-results.json', json_encode(['passed' => true, 'events' => array_column($rows, 'event_type'), 'correlations' => array_column($rows, 'correlation_id'), 'timestamp' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Native observability passed: actual source/storage failures produce correlated, typed events without provider text or input/configuration.\n";
