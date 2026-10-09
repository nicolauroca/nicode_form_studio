<?php
declare(strict_types=1);

$counterScope = hash('sha256', random_bytes(32));
for ($counter = 0; $counter < 12; $counter++) { $connection->insert('rate_limits', ['scope_hash' => $counterScope, 'window_start' => gmdate('Y-m-d H:i:s', $counter), 'expires_at' => '1970-01-02 00:00:00', 'attempts' => 3]); }
$freshCounter = $connection->insert('rate_limits', ['scope_hash' => $counterScope, 'window_start' => gmdate('Y-m-d H:i:s'), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400), 'attempts' => 7]);
$counterCleaner = new Nicode\FormStudio\Jobs\RateLimitCleanupHandler($connection);
$counterLease = new Nicode\FormStudio\Jobs\JobLease(1, Nicode\FormStudio\Domain\Uuid::create(), 'rate-limit-cleanup', 0, [], [], str_repeat('b', 64), 1, 0, 0);
try { $connection->transaction(function () use ($counterCleaner, $counterLease): void { $counterCleaner->run($counterLease, 5); throw new RuntimeException('Counter rollback.'); }); } catch (RuntimeException $error) { if ($error->getMessage() !== 'Counter rollback.') { throw $error; } }
if ((int) $connection->row('SELECT COUNT(*) AS total FROM ' . $connection->table('rate_limits') . ' WHERE scope_hash = :scope', [':scope' => $counterScope])['total'] !== 13) { throw new RuntimeException('Counter cleanup escaped rollback.'); }
for ($batch = 0; $batch < 3; $batch++) {
    $progress = $connection->transaction(fn () => $counterCleaner->run($counterLease, 5));
    if ($progress->processed > 5) { throw new RuntimeException('Counter cleanup exceeded chunk.'); }
}
$remainingCounters = $connection->rows('SELECT id, attempts FROM ' . $connection->table('rate_limits') . ' WHERE scope_hash = :scope', [':scope' => $counterScope]);
if (count($remainingCounters) !== 1 || (int) $remainingCounters[0]['id'] !== $freshCounter || (int) $remainingCounters[0]['attempts'] !== 7) { throw new RuntimeException('Counter cleanup lost active limiter state or retained expired scope.'); }
echo "Rate counter expiry: bounded transactional deletion, rollback and active-window preservation passed.\n";
