<?php
declare(strict_types=1);

$attemptForms = $runtime->get(Nicode\FormStudio\Infrastructure\Database\FormRepository::class);
$attemptResponses = $runtime->get(Nicode\FormStudio\Infrastructure\Database\SubmissionRepository::class);
$attemptCases = [];
foreach (['none', 'metadata', 'full'] as $mode) {
    $fixtureForm = $administration->create('Scheduled attempt cleanup', 'scheduled-attempt-' . bin2hex(random_bytes(6)), (int) $admin->id);
    $draft = $administration->edit($fixtureForm, (int) $admin->id)['draft']; $uuid = Nicode\FormStudio\Domain\Uuid::create();
    $draft['elements'] = [['uuid' => $uuid, 'type' => 'field']]; $draft['fields'] = [['uuid' => $uuid, 'name' => 'answer', 'type' => 'text']];
    $draft['actions'] = []; $draft['persistence']['mode'] = $mode; $draft['security']['captcha'] = ['mode' => 'none'];
    $revision = $administration->save($fixtureForm, 0, $draft, (int) $admin->id); $version = $administration->publish($fixtureForm, $revision, (int) $admin->id);
    $spec = $attemptForms->version($fixtureForm, $version);
    foreach (['expired', 'fresh'] as $age) {
        $stored = $attemptResponses->persist($fixtureForm, $version, $spec, [$uuid => 'Scheduled synthetic answer'], hash('sha256', random_bytes(32)));
        if ($age === 'expired') { $db->execute('UPDATE ' . $db->table('attempts') . ' SET expires_at=:date WHERE submission_uuid=:uuid', [':date' => '2000-01-01 00:00:00', ':uuid' => $stored->uuid]); }
        $attemptCases[] = [$fixtureForm, $stored, $mode, $age, $attemptResponses->get($fixtureForm, $stored->id)];
    }
    $administration->deactivate($fixtureForm, (int) $attemptForms->get($fixtureForm)['draft_revision'], (int) $admin->id);
}
$attemptJob = $cleanupQueue->queueAttemptCleanup();
if ($attemptJob === null || $cleanupQueue->queueAttemptCleanup() !== null) { throw new RuntimeException('Native attempt cleanup deduplication failed.'); }
$deadline = microtime(true) + 55;
while ($repository->get($attemptJob)['state'] !== 'completed' && microtime(true) < $deadline) {
    $output = []; exec($command, $output, $exit);
    if ($exit !== 0 || in_array($repository->get($attemptJob)['state'], ['failed', 'cancelled'], true)) { throw new RuntimeException('Native scheduler attempt cleanup failed.'); }
}
if ($repository->get($attemptJob)['state'] !== 'completed') { throw new RuntimeException('Native attempt cleanup did not complete within fixture deadline.'); }
foreach ($attemptCases as [$fixtureForm, $stored, $mode, $age, $before]) {
    $response = $db->row('SELECT * FROM ' . $db->table('submissions') . ' WHERE id=:id', [':id' => $stored->id]);
    $receipt = $db->row('SELECT id FROM ' . $db->table('attempts') . ' WHERE form_id=:form AND attempt_hash=:hash', [':form' => $fixtureForm, ':hash' => $before['attempt_hash']]);
    if (($age === 'fresh') !== ($receipt !== null)) { throw new RuntimeException('Scheduled attempt cleanup violated receipt expiry.'); }
    if ($mode === 'none' && $age === 'expired') {
        if ($response !== null) { throw new RuntimeException('Abandoned no-storage response survived scheduled cleanup.'); }
    } elseif ($response !== $before) { throw new RuntimeException('Scheduled attempt cleanup changed a protected response.'); }
}
file_put_contents($root . '/build/native-attempt-cleanup-results.json', json_encode(['passed' => true, 'task_id' => $taskId, 'job_id' => $attemptJob, 'cases' => 6, 'timestamp' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Native scheduled attempt cleanup: deduplicated installed handler, CLI completion, six expiry/storage cases and exact protected response preservation passed.\n";
