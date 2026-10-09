<?php
declare(strict_types=1);
$technicalLog = new Nicode\FormStudio\Infrastructure\Database\TechnicalLog($connection);
$logCorrelation = Nicode\FormStudio\Domain\Uuid::create();
$logAllow = static fn (int $actor, ?int $form, string $permission): bool => $actor === 1;
foreach (range(1, 102) as $n) { $technicalLog->record('ERROR', 'submission.unexpected', $logCorrelation, ['version_id' => $n]); }
$logPage = $technicalLog->page(1, $logAllow, correlation: $logCorrelation);
if (count($logPage['rows']) !== 100 || $logPage['next_before'] === null) { throw new RuntimeException('Technical log pagination unbounded or missing continuation.'); }
$logNext = $technicalLog->page(1, $logAllow, $logPage['next_before'], $logCorrelation);
if (count($logNext['rows']) !== 2 || $logNext['next_before'] !== null || array_intersect(array_column($logPage['rows'], 'id'), array_column($logNext['rows'], 'id')) !== []) { throw new RuntimeException('Technical log cursor repeated or lost entries.'); }
try { $technicalLog->page(2, $logAllow); throw new RuntimeException('Denied actor read logs.'); } catch (DomainException) {}
try { $technicalLog->record('ERROR', 'submission.unexpected', $logCorrelation, ['message' => 'secret@example.invalid']); throw new RuntimeException('Free-text log input accepted.'); } catch (InvalidArgumentException) {}
try { $technicalLog->record('ERROR', 'secret@example.invalid', $logCorrelation); throw new RuntimeException('Untrusted event accepted.'); } catch (InvalidArgumentException) {}
try { $technicalLog->record('ERROR', 'submission.unexpected', $logCorrelation, ['job_id' => 'secret']); throw new RuntimeException('Untyped log reference accepted.'); } catch (InvalidArgumentException) {}
$debugCorrelation = Nicode\FormStudio\Domain\Uuid::create();
$technicalLog->record('DEBUG', 'job.retry', $debugCorrelation);
if ($technicalLog->page(1, $logAllow, correlation: $debugCorrelation)['rows'] !== []) { throw new RuntimeException('Debug logging enabled by default.'); }
(new Nicode\FormStudio\Infrastructure\Database\TechnicalLog($connection, true))->record('DEBUG', 'job.retry', $debugCorrelation);
if (count($technicalLog->page(1, $logAllow, correlation: $debugCorrelation)['rows']) !== 1) { throw new RuntimeException('Explicit debug logging failed.'); }
echo "Technical log: fixed safe vocabulary, typed references, default debug suppression, ACL and keyset pagination verified.\n";
$connection->execute('UPDATE ' . $connection->table('technical_log') . ' SET created_at = :old WHERE correlation_id = :correlation', [':old' => gmdate('Y-m-d H:i:s', time() - 31 * 86400), ':correlation' => $logCorrelation]);
$auditBefore = (int) $connection->row('SELECT COUNT(*) AS total FROM ' . $connection->table('audit_log'))['total'];
$logCleaner = new Nicode\FormStudio\Jobs\TechnicalLogCleanupHandler($connection);
$cleanupCursor = []; $cleaned = 0;
do {
    $cleanupLease = new Nicode\FormStudio\Jobs\JobLease(1, Nicode\FormStudio\Domain\Uuid::create(), 'technical-log-cleanup', 0, ['days' => 30], $cleanupCursor, str_repeat('a', 64), 1, $cleaned, 0);
    $cleanupProgress = $connection->transaction(static fn () => $logCleaner->run($cleanupLease, 50));
    $cleanupCursor = $cleanupProgress->cursor; $cleaned += $cleanupProgress->processed;
} while (!$cleanupProgress->complete);
if ($cleaned !== 102 || $technicalLog->page(1, $logAllow, correlation: $logCorrelation)['rows'] !== [] || count($technicalLog->page(1, $logAllow, correlation: $debugCorrelation)['rows']) !== 1) { throw new RuntimeException('Technical retention deleted fresh rows or skipped expired rows.'); }
if ($auditBefore !== (int) $connection->row('SELECT COUNT(*) AS total FROM ' . $connection->table('audit_log'))['total']) { throw new RuntimeException('Technical cleanup touched audit records.'); }
echo "Technical retention: bounded date/ID cursor deletes expired events and preserves fresh events and audit records.\n";
