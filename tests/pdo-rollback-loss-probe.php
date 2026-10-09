<?php
declare(strict_types=1);
// Probe only: no data writes, no changes to the runtime adapter.
define('_JEXEC', 1); $root = dirname(__DIR__);
require $root . '/build/joomla-6.0.0/libraries/vendor/autoload.php'; require $root . '/src/lib_nicode_form_studio/autoload.php';
$config = json_decode(ltrim(file_get_contents($root . '/build/database-test.json'), "\xEF\xBB\xBF"), true, 32, JSON_THROW_ON_ERROR);
if ($config['host'] !== '127.0.0.1' || $config['port'] !== 13367 || $config['database'] !== 'formstudio_test') { throw new RuntimeException('Refusing non-test fixture.'); }
$driver = (new Joomla\Database\DatabaseFactory())->getDriver('mysql', ['host' => '127.0.0.1', 'port' => 13367, 'user' => $config['user'], 'password' => $config['password'], 'database' => 'formstudio_test', 'prefix' => 'nfs_']);
$driver->connect(); unset($config); $db = new Nicode\FormStudio\Infrastructure\Database\Connection($driver);
try { $db->transaction(function () use ($driver): void { $driver->getConnection()->rollBack(); throw new RuntimeException('Synthetic transaction loss.'); }); }
catch (Throwable $error) { $first = $error->getMessage(); }
$queryAccepted = false; $callbackRanWithoutTransaction = false; $laterError = null;
try { $queryAccepted = $db->row('SELECT 1 AS ready') !== null; } catch (Throwable) {}
try { $db->transaction(function () use ($driver, &$callbackRanWithoutTransaction): void { $callbackRanWithoutTransaction = !$driver->getConnection()->inTransaction(); }); } catch (Throwable $error) { $laterError = get_class($error); }
echo json_encode(['first_error' => $first ?? null, 'later_query_accepted' => $queryAccepted, 'later_callback_ran_without_transaction' => $callbackRanWithoutTransaction, 'later_error' => $laterError], JSON_THROW_ON_ERROR) . "\n";
