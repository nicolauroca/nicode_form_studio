<?php
declare(strict_types=1);

// Read/recovery experiment against disposable databases only. The strict
// statement is local to this process; no runtime or installed driver is edited.
define('_JEXEC', 1); $root = dirname(__DIR__);
require $root . '/build/joomla-6.0.0/libraries/vendor/autoload.php';
require $root . '/src/lib_nicode_form_studio/autoload.php';
final class FixtureStrictStatement extends PDOStatement
{
    protected function __construct() {}
    public function execute(?array $params = null): bool
    {
        try { return parent::execute($params); }
        catch (PDOException $error) { throw new Joomla\Database\Exception\ExecutionFailureException($this->queryString, 'Synthetic statement failure.', $error->getCode(), $error); }
    }
}
$engine = $argv[1] ?? ''; $strict = in_array('--strict', $argv, true);
$fixtures = ['mysql' => ['', 13367, 'formstudio_test'], 'mysql8' => ['-mysql8', 13373, 'formstudio_test_mysql8'], 'postgresql' => ['-postgresql', 13368, 'formstudio_test_pg']];
if (!isset($fixtures[$engine])) { throw new RuntimeException('Choose an isolated fixture.'); }
[$suffix, $port, $database] = $fixtures[$engine];
$config = json_decode(ltrim(file_get_contents($root . '/build/database-test' . $suffix . '.json'), "\xEF\xBB\xBF"), true, 32, JSON_THROW_ON_ERROR);
if ($config['host'] !== '127.0.0.1' || $config['port'] !== $port || $config['database'] !== $database) { throw new RuntimeException('Refusing non-test database.'); }
$driver = (new Joomla\Database\DatabaseFactory())->getDriver($engine === 'postgresql' ? 'pgsql' : 'mysql', ['host' => '127.0.0.1', 'port' => $port, 'user' => $config['user'], 'password' => $config['password'], 'database' => $database, 'prefix' => 'nfs_', 'charset' => 'utf8mb4']);
$driver->connect(); unset($config); $native = $driver->getConnection();
if (!$native instanceof PDO) { throw new RuntimeException('PDO fixture required.'); }
$previous = $native->getAttribute(PDO::ATTR_STATEMENT_CLASS);
if ($strict) { $native->setAttribute(PDO::ATTR_STATEMENT_CLASS, [FixtureStrictStatement::class]); }
$db = new Nicode\FormStudio\Infrastructure\Database\Connection($driver);
$existingScope = hash('sha256', random_bytes(32)); $firstScope = hash('sha256', random_bytes(32)); $laterScope = hash('sha256', random_bytes(32));
$insert = static fn (string $scope): int => $db->insert('rate_limits', ['scope_hash' => $scope, 'window_start' => '2030-01-01 00:00:00', 'expires_at' => '2030-01-01 00:01:00', 'attempts' => 1]);
try {
    $insert($existingScope); $failure = null;
    try { $db->transaction(function () use ($insert, $firstScope, $existingScope): void { $insert($firstScope); $insert($existingScope); }); }
    catch (Throwable $error) { $failure = get_class($error); }
    $sameConnection = $native === $driver->getConnection();
    $danglingTransaction = $native->inTransaction();
    // Release only this experiment's abandoned original connection.
    if ($danglingTransaction) { $native->rollBack(); }
    $firstRolledBack = $db->row('SELECT id FROM ' . $db->table('rate_limits') . ' WHERE scope_hash = :scope', [':scope' => $firstScope]) === null;
    $laterTransaction = true;
    try { $db->transaction(fn () => $insert($laterScope)); } catch (Throwable) { $laterTransaction = false; }
    $report = ['engine' => $engine, 'strict_fixture_statement' => $strict, 'failure' => $failure, 'same_connection' => $sameConnection, 'dangling_transaction' => $danglingTransaction, 'prior_write_rolled_back' => $firstRolledBack, 'later_transaction_works' => $laterTransaction];
    echo json_encode($report, JSON_THROW_ON_ERROR) . "\n";
    if ($strict && (!$sameConnection || $danglingTransaction || !$firstRolledBack || !$laterTransaction || $failure !== Joomla\Database\Exception\ExecutionFailureException::class)) { throw new RuntimeException('Strict fixture did not preserve transaction semantics.'); }
} finally {
    if ($native->inTransaction()) { $native->rollBack(); }
    $native->setAttribute(PDO::ATTR_STATEMENT_CLASS, $previous);
    foreach ([$existingScope, $firstScope, $laterScope] as $scope) { $db->execute('DELETE FROM ' . $db->table('rate_limits') . ' WHERE scope_hash = :scope', [':scope' => $scope]); }
}
