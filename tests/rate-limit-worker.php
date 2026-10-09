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
echo "ready\n"; flush();
if (trim((string) fgets(STDIN)) !== 'go') { exit(2); }
$result = (new Nicode\FormStudio\Infrastructure\Database\RateLimiter(new Nicode\FormStudio\Infrastructure\Database\Connection($driver), static fn (): int => 1900000000))->consume($scope, 3, 60);
echo $result->allowed ? "allowed\n" : "denied\n";
