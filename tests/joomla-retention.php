<?php
declare(strict_types=1);
require __DIR__ . '/joomla-scheduler.php';
$retentionForm = $administration->create('Native retention fixture', 'native-retention-' . bin2hex(random_bytes(4)), (int) $admin->id);
$retentionDraft = $administration->edit($retentionForm, (int) $admin->id)['draft']; $retentionField = Nicode\FormStudio\Domain\Uuid::create();
$retentionDraft['elements'] = [['uuid' => $retentionField, 'type' => 'field']];
$retentionDraft['fields'] = [['uuid' => $retentionField, 'name' => 'retention_answer', 'type' => 'text', 'config' => []]];
$retentionResponses = [];
foreach (['anonymize', 'delete'] as $retentionOperation) {
    $retentionDraft['privacy'] = ['retention' => ['action' => $retentionOperation, 'amount' => 1, 'unit' => 'days']];
    $currentRevision = $administration->edit($retentionForm, (int) $admin->id)['form']['draft_revision'];
    $nextRevision = $administration->save($retentionForm, (int) $currentRevision, $retentionDraft, (int) $admin->id);
    $retentionVersion = $administration->publish($retentionForm, $nextRevision, (int) $admin->id);
    $retentionSpec = $runtime->get(Nicode\FormStudio\Infrastructure\Database\FormRepository::class)->version($retentionForm, $retentionVersion);
    $retentionResponses[$retentionOperation] = $runtime->get(Nicode\FormStudio\Infrastructure\Database\SubmissionRepository::class)->persist($retentionForm, $retentionVersion, $retentionSpec, [$retentionField => 'Synthetic expired answer'], hash('sha256', random_bytes(32)), ['expires_at' => '2000-01-01 00:00:00'])->id;
}
$retentionQueue = new Nicode\FormStudio\Infrastructure\Joomla\JobMaintenance($db, $repository, static fn (): int => time() + 3601);
$retentionDispatch = $retentionQueue->queueRetention();
if ($retentionDispatch === null || $retentionQueue->queueRetention() !== null) { throw new RuntimeException('Native retention dispatch deduplication failed.'); }
for ($run = 0; $run < 15; $run++) {
    $output = []; exec($command, $output, $exit);
    if ($exit !== 0) { throw new RuntimeException('Native retention scheduler failed.'); }
    $remaining = $db->row('SELECT id FROM ' . $db->table('submissions') . ' WHERE form_id = :form AND expires_at IS NOT NULL LIMIT 1', [':form' => $retentionForm]);
    if ($remaining === null) { break; }
}
$anonymizedResponse = $db->row('SELECT anonymized_at, canonical_payload FROM ' . $db->table('submissions') . ' WHERE id = :id', [':id' => $retentionResponses['anonymize']]);
$deletedResponse = $db->row('SELECT id FROM ' . $db->table('submissions') . ' WHERE id = :id', [':id' => $retentionResponses['delete']]);
if (!$anonymizedResponse || $anonymizedResponse['anonymized_at'] === null || json_decode($anonymizedResponse['canonical_payload'], true)['values'] !== [] || $deletedResponse !== null) { throw new RuntimeException('Native scheduler did not honor both historical retention policies.'); }
file_put_contents($root . '/build/native-retention-results.json', json_encode(['passed' => true, 'timestamp' => gmdate(DATE_ATOM), 'form_id' => $retentionForm, 'checks' => ['native publication of finite retention', 'hourly dispatch deduplication', 'native CLI version-scoped anonymization and deletion', 'canonical erasure']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Native retention scheduler: original-version anonymization and deletion verified.\n";
