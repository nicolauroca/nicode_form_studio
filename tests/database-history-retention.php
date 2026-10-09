<?php
declare(strict_types=1);

$historyCorrelation = Nicode\FormStudio\Domain\Uuid::create();
for ($historyIndex = 0; $historyIndex < 12; $historyIndex++) { $connection->insert('audit_log', ['correlation_id' => $historyCorrelation, 'actor_id' => 1, 'event_type' => 'fixture.history', 'form_id' => null, 'submission_uuid' => null, 'created_at' => '1999-01-01 00:00:00', 'safe_metadata' => '{}']); }
$historyFresh = $connection->insert('audit_log', ['correlation_id' => $historyCorrelation, 'actor_id' => 1, 'event_type' => 'fixture.history', 'form_id' => null, 'submission_uuid' => null, 'created_at' => gmdate('Y-m-d H:i:s'), 'safe_metadata' => '{}']);
$historyDays = 30;
$historyCleaner = new Nicode\FormStudio\Jobs\OperationalHistoryCleanupHandler($connection, 'audit', static function () use (&$historyDays): int { return $historyDays; });
$historyLease = new Nicode\FormStudio\Jobs\JobLease(1, Nicode\FormStudio\Domain\Uuid::create(), 'audit-history-cleanup', 0, ['days' => 30], [], str_repeat('a', 64), 1, 0, 0);
try { $connection->transaction(function () use ($historyCleaner, $historyLease): void { $historyCleaner->run($historyLease, 5); throw new RuntimeException('History rollback.'); }); }
catch (RuntimeException $error) { if ($error->getMessage() !== 'History rollback.') { throw $error; } }
if ((int) $connection->row('SELECT COUNT(*) AS total FROM ' . $connection->table('audit_log') . ' WHERE correlation_id = :uuid', [':uuid' => $historyCorrelation])['total'] !== 13) { throw new RuntimeException('Audit cleanup escaped rollback.'); }
foreach ([0, 60] as $historyDays) {
    $historyCancelled = $connection->transaction(fn () => $historyCleaner->run($historyLease, 5));
    if (!$historyCancelled->complete || $historyCancelled->processed !== 0) { throw new RuntimeException('Changed history policy did not stop old cleanup.'); }
}
$historyDays = 30; $historyCursor = []; $historyProcessed = 0;
for ($historyBatch = 0; $historyBatch < 1000; $historyBatch++) {
    $historyLease = new Nicode\FormStudio\Jobs\JobLease(1, Nicode\FormStudio\Domain\Uuid::create(), 'audit-history-cleanup', 0, ['days' => 30], $historyCursor, str_repeat('a', 64), 1, $historyProcessed, 0);
    $historyProgress = $connection->transaction(fn () => $historyCleaner->run($historyLease, 5));
    if ($historyProgress->processed > 5) { throw new RuntimeException('History cleanup exceeded candidate bound.'); }
    $historyCursor = $historyProgress->cursor; $historyProcessed += $historyProgress->processed;
    if ($historyProgress->complete) { break; }
}
$historyRemaining = $connection->rows('SELECT id FROM ' . $connection->table('audit_log') . ' WHERE correlation_id = :uuid', [':uuid' => $historyCorrelation]);
if (!$historyProgress->complete || array_map('intval', array_column($historyRemaining, 'id')) !== [$historyFresh]) { throw new RuntimeException('Audit retention lost fresh records or skipped expired records.'); }

$actionHistoryForm = $forms->create('Action history retention', 'action-history-' . bin2hex(random_bytes(6)), 1);
$actionHistoryDraft = $forms->draft($actionHistoryForm); $actionHistoryField = Nicode\FormStudio\Domain\Uuid::create();
$actionHistoryDraft['elements'] = [['uuid' => $actionHistoryField, 'type' => 'field', 'parent_uuid' => null]];
$actionHistoryDraft['fields'] = [['uuid' => $actionHistoryField, 'name' => 'value', 'type' => 'text', 'config' => []]];
$actionHistoryRevision = $forms->saveDraft($actionHistoryForm, 0, $actionHistoryDraft, 1);
$actionHistoryVersion = $forms->publish($actionHistoryForm, $actionHistoryRevision, 1);
$actionHistorySubmission = $submissions->persist($actionHistoryForm, $actionHistoryVersion, $forms->version($actionHistoryForm, $actionHistoryVersion), [$actionHistoryField => 'test'], hash('sha256', random_bytes(32)));
$actionHistoryRuns = new Nicode\FormStudio\Infrastructure\Database\ActionRunRepository($connection);
$actionHistoryIds = []; $actionHistoryActions = [];
foreach (['succeeded', 'failed', 'running'] as $terminal) {
    $action = Nicode\FormStudio\Domain\Uuid::create(); $actionHistoryActions[$terminal] = $action;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $lease = $actionHistoryRuns->claim($actionHistorySubmission->id, $action, 'fixture.history', $attempt > 1);
        $actionHistoryIds[] = $lease->id;
        if ($attempt < 3 || $terminal !== 'running') { $actionHistoryRuns->finish($lease, $attempt < 3 ? 'failed' : $terminal, 'fixture_result'); }
    }
}
$connection->execute('UPDATE ' . $connection->table('action_runs') . ' SET created_at = :date WHERE submission_id = :submission', [':date' => '1999-01-01 00:00:00', ':submission' => $actionHistorySubmission->id]);
$actionCleaner = new Nicode\FormStudio\Jobs\OperationalHistoryCleanupHandler($connection, 'action', static fn (): int => 30);
$actionCursor = []; $actionProcessed = 0;
for ($batch = 0; $batch < 1000; $batch++) {
    $lease = new Nicode\FormStudio\Jobs\JobLease(1, Nicode\FormStudio\Domain\Uuid::create(), 'action-history-cleanup', 0, ['days' => 30], $actionCursor, str_repeat('a', 64), 1, $actionProcessed, 0);
    $progress = $connection->transaction(fn () => $actionCleaner->run($lease, 4));
    if ($progress->processed > 4) { throw new RuntimeException('Action history candidate bound exceeded.'); }
    $actionCursor = $progress->cursor; $actionProcessed += $progress->processed;
    if ($progress->complete) { break; }
}
$remaining = $connection->rows('SELECT action_uuid, attempt, state FROM ' . $connection->table('action_runs') . ' WHERE submission_id = :submission ORDER BY id', [':submission' => $actionHistorySubmission->id]);
if (!$progress->complete || count($remaining) !== 3 || array_map('intval', array_column($remaining, 'attempt')) !== [3, 3, 3] || array_column($remaining, 'state') !== ['succeeded', 'failed', 'running']) { throw new RuntimeException('Action cleanup discarded latest/running markers or retained superseded history.'); }
if ($actionHistoryRuns->claim($actionHistorySubmission->id, $actionHistoryActions['succeeded'], 'fixture.history') !== null || $actionHistoryRuns->claim($actionHistorySubmission->id, $actionHistoryActions['succeeded'], 'fixture.history', true, 3) !== null) { throw new RuntimeException('History cleanup enabled a duplicate successful action.'); }
$retry = $actionHistoryRuns->claim($actionHistorySubmission->id, $actionHistoryActions['failed'], 'fixture.history', true, 3);
if ($retry === null || $retry->attempt !== 4) { throw new RuntimeException('History cleanup lost monotonic retry identity.'); }
$actionHistoryRuns->finish($retry, 'succeeded', 'fixture_result');
echo "Operational history retention: bounded candidates, audit rollback/freshness, policy changes, latest/running Action markers, duplicate-delivery rejection and monotonic retry identity passed.\n";
