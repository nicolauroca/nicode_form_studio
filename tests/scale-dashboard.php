<?php
declare(strict_types=1);
$root = dirname(__DIR__); define('_JEXEC', 1);
require $root . '/build/joomla-6.0.0/libraries/vendor/autoload.php'; require $root . '/src/lib_nicode_form_studio/autoload.php';
$config = json_decode(ltrim(file_get_contents($root . '/build/database-test.json'), "\xEF\xBB\xBF"), true, 32, JSON_THROW_ON_ERROR);
if ($config['host'] !== '127.0.0.1' || $config['port'] !== 13367 || $config['database'] !== 'formstudio_test') { throw new RuntimeException('Refusing non-test scale server.'); }
$driver = (new Joomla\Database\DatabaseFactory())->getDriver('mysql', ['host' => '127.0.0.1', 'port' => 13367, 'user' => $config['user'], 'password' => $config['password'], 'database' => 'formstudio_scale', 'prefix' => 'scale_', 'charset' => 'utf8mb4']); $driver->connect();
$db = new Nicode\FormStudio\Infrastructure\Database\Connection($driver);
if ((int) $db->row('SELECT COUNT(*) AS total FROM ' . $db->table('submissions'))['total'] !== 1000000 || (int) $db->row('SELECT COUNT(*) AS total FROM ' . $db->table('forms'))['total'] !== 100) { throw new RuntimeException('Expected existing scale fixture.'); }
$db->execute('CREATE TABLE IF NOT EXISTS ' . $db->quote('#__assets') . ' (id BIGINT PRIMARY KEY, name VARCHAR(255) NOT NULL)');
$allow = static fn (): bool => true;
$manage = static fn (int $actor, array $rows): array => array_fill_keys(array_map('intval', array_column($rows, 'id')), ['core.edit' => true]);
$read = static fn (int $actor, array $rows): array => array_fill_keys(array_map('intval', array_column($rows, 'id')), []);
$fields = new Nicode\FormStudio\Registry\FieldTypeRegistry(); Nicode\FormStudio\Field\CoreFieldTypes::register($fields);
$compiler = new Nicode\FormStudio\Compiler\FormCompiler($fields, new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry());
$forms = new Nicode\FormStudio\Infrastructure\Database\FormRepository($db, $compiler);
$diagnose = static function (int $id) use ($forms, $compiler): array { return ['invalid' => !$compiler->compile($forms->draft($id))->successful()]; };
$latest = $db->row('SELECT MAX(received_at) AS latest FROM ' . $db->table('submissions'))['latest'];
$scaleNow = strtotime($latest . ' UTC') + 1;
$dashboard = new Nicode\FormStudio\Application\OperationsDashboard($db, $allow, $manage, $read, $diagnose, static fn (): int => $scaleNow);
$times = [];
for ($i = 0; $i < 5; $i++) {
    $start = hrtime(true); $report = $dashboard->report(1); $times[] = (hrtime(true) - $start) / 1e6;
    if ($report['readable_forms'] !== 100 || $report['diagnosed_forms'] !== 100 || count($report['recent']) !== 10 || count($report['modified']) !== 10 || $report['metrics']['responses_30'] < 1) { throw new RuntimeException('Dashboard scale scope mismatch.'); }
}
sort($times);
$result = ['timestamp' => gmdate(DATE_ATOM), 'database' => $driver->getVersion(), 'responses' => 1000000, 'forms' => 100, 'responses_30' => $report['metrics']['responses_30'], 'runs_ms' => $times, 'max_ms' => max($times), 'peak_memory_bytes' => memory_get_peak_usage(true), 'limitation' => 'Synthetic local MariaDB; compiler used for 100 drafts, no external deployment probes. Five observations are not a production SLO.'];
file_put_contents($root . '/build/scale-dashboard-results.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Dashboard over 1M responses/100 drafts: max ' . round(max($times), 2) . ' ms, peak ' . round(memory_get_peak_usage(true) / 1048576, 2) . " MiB.\n";
