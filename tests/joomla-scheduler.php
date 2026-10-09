<?php
declare(strict_types=1);
require __DIR__ . '/joomla-jobs.php';
$db->execute('UPDATE ' . $db->quote('#__extensions') . " SET enabled = 1 WHERE type = 'plugin' AND folder = 'task' AND element = 'nicode_form_studio'");
$taskComponent = $app->bootComponent('com_scheduler');
$taskModel = $taskComponent->getMVCFactory()->createModel('Task', 'Administrator', ['ignore_request' => true]);
$taskModel->setCurrentUser($admin);
$taskData = ['title' => 'FormStudio isolated worker ' . bin2hex(random_bytes(3)), 'type' => 'nicode.formstudio.jobs', 'state' => 1, 'priority' => 0, 'params' => ['batches' => 2, 'chunk_size' => 1], 'execution_rules' => ['rule-type' => 'manual', 'exec-day' => gmdate('d'), 'exec-time' => gmdate('H:i')]];
if (!$taskModel->save($taskData)) { throw new RuntimeException('Unable to prepare isolated scheduler task.'); }
$taskId = (int) $taskModel->getState('task.id');
if ($taskId < 1) { throw new RuntimeException('Scheduler fixture identity missing.'); }
$job = $runtime->get(Nicode\FormStudio\Application\JobAdministration::class)->enqueue((int) $admin->id, $form, 'export-csv', ['fields' => [$field]]);
file_put_contents($root . '/build/native-scheduler-fixture.json', json_encode(['task_id' => $taskId, 'job_id' => $job, 'form_id' => $form], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Manual isolated scheduler task prepared for explicit CLI execution.\n";
$repository = $runtime->get(Nicode\FormStudio\Infrastructure\Database\JobRepository::class);
$sawPending = false;
for ($run = 0; $run < 12; $run++) {
    $before = $repository->get($job);
    if ($before['state'] === 'completed') { break; }
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($site . '/cli/joomla.php') . ' scheduler:run --id=' . $taskId . ' --no-interaction';
    $output = []; exec($command, $output, $exit);
    if ($exit !== 0) { throw new RuntimeException('Native scheduler CLI failed with code ' . $exit); }
    $after = $repository->get($job);
    if ((int) $after['processed'] - (int) $before['processed'] > 2) { throw new RuntimeException('Scheduler exceeded configured batch limit.'); }
    $sawPending = $sawPending || ($after['state'] === 'pending' && (int) $after['processed'] > 0);
}
$finished = $repository->get($job);
if (!$sawPending || $finished['state'] !== 'completed' || (int) $finished['processed'] !== 3) { throw new RuntimeException('Scheduler failed to resume export across CLI processes.'); }
$download = $runtime->get(Nicode\FormStudio\Application\ExportDownloads::class)->open($job, (int) $admin->id);
$rows = []; while (($csvRow = fgetcsv($download->stream, escape: '')) !== false) { $rows[] = $csvRow; } fclose($download->stream);
if (count($rows) !== 4 || count(array_unique(array_column(array_slice($rows, 1), 0))) !== 3) { throw new RuntimeException('Scheduler export repeated or lost a response.'); }
$db->execute('UPDATE ' . $db->table('jobs') . ' SET expires_at = :past WHERE id = :id', [':past' => '2000-01-01 00:00:00', ':id' => $job]);
$cleanupQueue = new Nicode\FormStudio\Infrastructure\Joomla\JobMaintenance($db, $repository, static fn (): int => time() + 3601);
$cleanupJob = $cleanupQueue->queueExportCleanup();
if ($cleanupJob === null || $cleanupQueue->queueExportCleanup() !== null) { throw new RuntimeException('Scheduled cleanup deduplication failed.'); }
// Other hourly maintenance shares this worker; cleanup is queued, not an
// assumption that the very next two single-row batches belong to this export.
if (!$taskModel->save(array_replace($taskData, ['id' => $taskId, 'params' => ['batches' => 10, 'chunk_size' => 50]]))) { throw new RuntimeException('Unable to configure fixture maintenance batches.'); }
for ($run = 0; $run < 20 && $repository->get($cleanupJob)['state'] !== 'completed'; $run++) {
    $output = []; exec($command, $output, $exit);
    if ($exit !== 0) { throw new RuntimeException('Scheduled maintenance command failed.'); }
}
if ($repository->get($cleanupJob)['state'] !== 'completed' || $repository->get($job)['artifact_key'] !== null) { throw new RuntimeException('Scheduled cleanup did not expire the artifact.'); }
if (is_file($root . '/build/native-private-exports/' . $finished['uuid'] . '.csv')) { throw new RuntimeException('Expired CSV survived cleanup.'); }
require __DIR__ . '/joomla-history-retention.php';
require __DIR__ . '/joomla-attempt-cleanup.php';
file_put_contents($root . '/build/native-scheduler-results.json', json_encode(['passed' => true, 'timestamp' => gmdate(DATE_ATOM), 'task_id' => $taskId, 'job_id' => $job, 'checks' => ['installed task plugin discovery', 'native scheduler CLI execution', 'bounded batches', 'durable resume across processes', 'exact private export rows', 'deduplicated hourly cleanup', 'expired artifact deletion', 'live history policy cancellation', 'audit expiry', 'retained action idempotency marker']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Native scheduler CLI: installed plugin, bounded chunks and cross-process export resume passed.\n";
