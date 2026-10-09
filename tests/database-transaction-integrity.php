<?php
declare(strict_types=1);

$transactionScopes = array_map(static fn (): string => hash('sha256', random_bytes(32)), range(1, 4));
$transactionNative = $driver->getConnection();
$transactionStatementClass = $transactionNative instanceof PDO ? $transactionNative->getAttribute(PDO::ATTR_STATEMENT_CLASS) : null;
$transactionInsert = static fn (string $scope): int => $connection->insert('rate_limits', ['scope_hash' => $scope, 'window_start' => '2031-01-01 00:00:00', 'expires_at' => '2031-01-01 00:01:00', 'attempts' => 1]);
try {
    $transactionInsert($transactionScopes[0]);
    $connection->transaction(function () use ($connection, $transactionInsert, $transactionScopes): void {
        $transactionInsert($transactionScopes[1]);
        try {
            $connection->transaction(function () use ($transactionInsert, $transactionScopes): void { $transactionInsert($transactionScopes[2]); $transactionInsert($transactionScopes[0]); });
            throw new RuntimeException('Duplicate uniqueness violation disappeared.');
        } catch (Joomla\Database\Exception\ExecutionFailureException) {}
        $transactionInsert($transactionScopes[3]);
    });
    foreach ($transactionScopes as $index => $scope) {
        $present = $connection->row('SELECT id FROM ' . $connection->table('rate_limits') . ' WHERE scope_hash = :scope', [':scope' => $scope]) !== null;
        if ($present !== ($index !== 2)) { throw new RuntimeException('Nested constraint failure escaped savepoint rollback or lost outer writes.'); }
    }
    if ($driver->getConnection() !== $transactionNative || ($transactionNative instanceof PDO && $transactionNative->getAttribute(PDO::ATTR_STATEMENT_CLASS) !== $transactionStatementClass)) { throw new RuntimeException('Query adaptation changed the shared connection or leaked statement configuration.'); }
    echo "Native query transaction integrity: constraint error preserves connection, savepoint rollback and outer commit; statement configuration restored.\n";
} finally { foreach ($transactionScopes as $scope) { $connection->execute('DELETE FROM ' . $connection->table('rate_limits') . ' WHERE scope_hash = :scope', [':scope' => $scope]); } }

// A deadlock victim can lose its transaction before PDO attempts rollback.
// Simulate this on a second disposable driver, never on the suite connection.
$lostDriver = (new Joomla\Database\DatabaseFactory())->getDriver($postgres ? 'pgsql' : 'mysql', ['host' => '127.0.0.1', 'port' => $port, 'user' => $config['user'], 'password' => $config['password'], 'database' => $databaseName, 'prefix' => 'nfs_', 'charset' => 'utf8mb4']);
$lostDriver->connect(); $lostDb = new Nicode\FormStudio\Infrastructure\Database\Connection($lostDriver);
try {
    $lostDb->transaction(function () use ($lostDriver): void { $lostDriver->getConnection()->rollBack(); throw new RuntimeException('Synthetic transaction loss.'); });
    throw new LogicException('Transaction loss was ignored.');
} catch (RuntimeException $expected) { if ($expected->getPrevious()?->getMessage() !== 'Synthetic transaction loss.') { throw $expected; } }
foreach ([fn () => $lostDb->row('SELECT 1 AS result'), fn () => $lostDb->transaction(static fn () => 1)] as $operation) {
    try { $operation(); throw new LogicException('Failed transaction context was reused.'); }
    catch (RuntimeException $expected) { if ($expected->getMessage() !== 'Database transaction context was lost.') { throw $expected; } }
}
echo "Lost transaction recovery: failed rollback fences subsequent queries and retries.\n";
