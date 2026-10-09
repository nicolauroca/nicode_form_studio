<?php
declare(strict_types=1);
// Included by the guarded native scheduler fixture; policy changes are restored.
$historyExtension = $db->row('SELECT extension_id, params FROM ' . $db->quote('#__extensions') . " WHERE type = 'component' AND element = 'com_nicode_form_studio'");
$historyParameters = json_decode($historyExtension['params'], true, 512, JSON_THROW_ON_ERROR);
$setHistoryPolicy = static function (int $days) use ($db, $historyExtension, $historyParameters): void {
    $db->execute('UPDATE ' . $db->quote('#__extensions') . ' SET params = :params WHERE extension_id = :id', [':params' => json_encode(array_replace($historyParameters, ['audit_log_days' => $days, 'action_history_days' => $days]), JSON_THROW_ON_ERROR), ':id' => (int) $historyExtension['extension_id']]);
};
$runHistoryJob = static function (int $id) use ($repository, $command): void {
    for ($run = 0; $run < 20 && $repository->get($id)['state'] !== 'completed'; $run++) {
        $output = []; exec($command, $output, $exit);
        if ($exit !== 0) { throw new RuntimeException('Native history scheduler failed.'); }
    }
    if ($repository->get($id)['state'] !== 'completed') { throw new RuntimeException('Native history job did not complete.'); }
};
$historyCorrelation = Nicode\FormStudio\Domain\Uuid::create();
try {
    $historyAuditId = $db->insert('audit_log', ['correlation_id' => $historyCorrelation, 'actor_id' => (int) $admin->id, 'event_type' => 'fixture.history', 'form_id' => $form, 'submission_uuid' => null, 'created_at' => '1999-01-01 00:00:00', 'safe_metadata' => '{}']);
    $setHistoryPolicy(0);
    $cancelledHistoryJob = $repository->enqueue('audit-history-cleanup', ['days' => 30], 0);
    $runHistoryJob($cancelledHistoryJob);
    if ((int) $repository->get($cancelledHistoryJob)['processed'] !== 0 || $db->row('SELECT id FROM ' . $db->table('audit_log') . ' WHERE id = :id', [':id' => $historyAuditId]) === null) { throw new RuntimeException('Disabled live history policy was ignored.'); }
    $historyAction = Nicode\FormStudio\Domain\Uuid::create();
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $historyLease = $actionRuns->claim($responseIds[0], $historyAction, 'fixture.history', $attempt > 1);
        $actionRuns->finish($historyLease, $attempt < 3 ? 'failed' : 'succeeded', 'fixture_result');
    }
    $db->execute('UPDATE ' . $db->table('action_runs') . ' SET created_at = :date WHERE submission_id = :submission AND action_uuid = :action', [':date' => '1999-01-01 00:00:00', ':submission' => $responseIds[0], ':action' => $historyAction]);
    $setHistoryPolicy(30);
    // Start fresh hourly maintenance after the deliberately cancelled policy job.
    $historyQueue = new Nicode\FormStudio\Infrastructure\Joomla\JobMaintenance($db, $repository, static fn (): int => time() + 3601);
    $auditHistoryJob = $historyQueue->queueHistoryCleanup('audit', 30);
    $actionHistoryJob = $historyQueue->queueHistoryCleanup('action', 30);
    if ($auditHistoryJob === null || $actionHistoryJob === null || $historyQueue->queueHistoryCleanup('audit', 30) !== null) { throw new RuntimeException('Native history queue deduplication failed.'); }
    $runHistoryJob($auditHistoryJob); $runHistoryJob($actionHistoryJob);
    if ($db->row('SELECT id FROM ' . $db->table('audit_log') . ' WHERE id = :id', [':id' => $historyAuditId]) !== null) { throw new RuntimeException('Native audit history survived expiry.'); }
    $historySurvivors = $db->rows('SELECT attempt, state FROM ' . $db->table('action_runs') . ' WHERE submission_id = :submission AND action_uuid = :action', [':submission' => $responseIds[0], ':action' => $historyAction]);
    if (count($historySurvivors) !== 1 || (int) $historySurvivors[0]['attempt'] !== 3 || $historySurvivors[0]['state'] !== 'succeeded' || $actionRuns->claim($responseIds[0], $historyAction, 'fixture.history') !== null) { throw new RuntimeException('Native history cleanup broke action idempotency.'); }
} finally {
    $db->execute('UPDATE ' . $db->quote('#__extensions') . ' SET params = :params WHERE extension_id = :id', [':params' => $historyExtension['params'], ':id' => (int) $historyExtension['extension_id']]);
}
echo "Native operational history: live policy cancellation, hourly deduplication, audit expiry and action idempotency passed.\n";
