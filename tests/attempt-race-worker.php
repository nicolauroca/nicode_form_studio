<?php
declare(strict_types=1);
define('_JEXEC', 1);
$root = dirname(__DIR__);
require $root . '/build/joomla-6.0.0/libraries/vendor/autoload.php';
require $root . '/src/lib_nicode_form_studio/autoload.php';
$engine = $argv[1] ?? ''; $scope = $argv[2] ?? '';
$fixtures = ['mysql' => ['', 13367, 'formstudio_test'], 'mysql8' => ['-mysql8', 13373, 'formstudio_test_mysql8'], 'postgresql' => ['-postgresql', 13368, 'formstudio_test_pg']];
if (!isset($fixtures[$engine]) || preg_match('/^[a-f0-9]{64}$/D', $scope) !== 1) { throw new RuntimeException('Invalid isolated rate worker.'); }
[$suffix, $port, $database] = $fixtures[$engine];
$config = json_decode(ltrim(file_get_contents($root . '/build/database-test' . $suffix . '.json'), "\xEF\xBB\xBF"), true, 32, JSON_THROW_ON_ERROR);
if ($config['host'] !== '127.0.0.1' || $config['port'] !== $port || $config['database'] !== $database) { throw new RuntimeException('Refusing non-isolated rate worker.'); }
$driver = (new Joomla\Database\DatabaseFactory())->getDriver($engine === 'postgresql' ? 'pgsql' : 'mysql', ['host' => '127.0.0.1', 'port' => $port, 'user' => $config['user'], 'password' => $config['password'], 'database' => $database, 'prefix' => 'nfs_', 'charset' => 'utf8mb4']);
$driver->connect(); unset($config);
$fixture = json_decode(file_get_contents($root . '/build/attempt-race-' . $scope . '.json'), true, flags: JSON_THROW_ON_ERROR);
$mode = $argv[3] ?? ''; $hold = ($argv[4] ?? '') === 'hold';
if (!in_array($mode, ['complete', 'cleanup'], true)) { throw new RuntimeException('Invalid race operation.'); }
$db = new Nicode\FormStudio\Infrastructure\Database\Connection($driver);
$fields = new Nicode\FormStudio\Registry\FieldTypeRegistry(); Nicode\FormStudio\Field\CoreFieldTypes::register($fields);
$forms = new Nicode\FormStudio\Infrastructure\Database\FormRepository($db, new Nicode\FormStudio\Compiler\FormCompiler($fields, new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry()));
echo "ready\n"; flush(); if (trim((string) fgets(STDIN)) !== 'go') { exit(2); }
echo "started\n"; flush();
$db->transaction(function () use ($db, $fields, $forms, $fixture, $mode, $hold): void {
    if ($mode === 'complete') {
        $db->row('SELECT id FROM ' . $db->table('forms') . ' WHERE id=:id FOR UPDATE', [':id' => $fixture['form']]);
        $db->row('SELECT id FROM ' . $db->table('submissions') . ' WHERE id=:id FOR UPDATE', [':id' => $fixture['submission']]);
        if ($hold) { echo "locked\n"; flush(); if (trim((string) fgets(STDIN)) !== 'release') { throw new RuntimeException('Race release missing.'); } }
        $responses = new Nicode\FormStudio\Infrastructure\Database\SubmissionRepository($db, new Nicode\FormStudio\Search\IndexProjector($fields), str_repeat('r', 32));
        $responses->completeAttempt($fixture['form'], $fixture['hash'], ['accepted' => true, 'category' => 'success'], true);
    } else {
        $handler = new Nicode\FormStudio\Jobs\AttemptCleanupHandler($db, $forms, new Nicode\FormStudio\Infrastructure\Database\JobRepository($db));
        $lease = new Nicode\FormStudio\Jobs\JobLease(1, Nicode\FormStudio\Domain\Uuid::create(), 'attempt-cleanup', 0, [], ['cutoff' => $fixture['expiry'], 'expires_at' => $fixture['expiry'], 'id' => $fixture['attempt'] - 1], str_repeat('a', 64), 1, 0, 0);
        $handler->run($lease, 1);
        if ($hold) { echo "locked\n"; flush(); if (trim((string) fgets(STDIN)) !== 'release') { throw new RuntimeException('Race release missing.'); } }
    }
});
echo "done\n";
