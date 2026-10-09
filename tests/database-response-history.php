<?php
declare(strict_types=1);

$historyForm = $forms->create('History fixture', 'history-' . bin2hex(random_bytes(6)), 1);
$historyDraft = $forms->draft($historyForm); $consentField = Nicode\FormStudio\Domain\Uuid::create();
$historyDraft['elements'] = [['uuid' => $consentField, 'type' => 'field']];
$historyDraft['fields'] = [['uuid' => $consentField, 'name' => 'consent', 'type' => 'consent', 'sensitive' => true, 'config' => ['label' => 'Exact historical consent <script>']]];
$forms->saveDraft($historyForm, 0, $historyDraft, 1); $historyVersion = $forms->publish($historyForm, 1, 1);
$historyResponse = $submissions->persist($historyForm, $historyVersion, $forms->version($historyForm, $historyVersion), [$consentField => true], hash('sha256', random_bytes(32)));
$historyOther = $submissions->persist($historyForm, $historyVersion, $forms->version($historyForm, $historyVersion), [$consentField => false], hash('sha256', random_bytes(32)));
$historyAuthorize = static fn (int $actor, ?int $form, string $permission): bool => $actor === 1 || ($actor === 2 && in_array($permission, ['core.manage', 'formstudio.submissions.view'], true));
$historyReader = new Nicode\FormStudio\Application\SubmissionReader($submissions, $forms, $connection, $historyAuthorize);
$historyExplorer = new Nicode\FormStudio\Application\SubmissionExplorer($connection, $forms, $search, $historyReader, $historyAuthorize, static fn (): array => [], $registry);
$historyIds = ['notes' => [], 'actions' => [], 'audit' => []];
for ($i = 0; $i < 105; $i++) {
    $historyIds['notes'][] = $connection->insert('submission_notes', ['submission_id' => $historyResponse->id, 'created_by' => 1, 'created_at' => '2026-09-27 10:00:00', 'body' => 'History note ' . $i]);
    $historyIds['actions'][] = $connection->insert('action_runs', ['submission_id' => $historyResponse->id, 'action_uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'action_type' => 'fixture', 'attempt' => 1, 'state' => 'failed', 'created_at' => '2026-09-27 10:00:00', 'revision' => 0]);
    $historyIds['audit'][] = $connection->insert('audit_log', ['form_id' => $historyForm, 'submission_uuid' => $historyResponse->uuid, 'correlation_id' => Nicode\FormStudio\Domain\Uuid::create(), 'actor_id' => 1, 'event_type' => 'submission.note', 'created_at' => '2026-09-27 10:00:00', 'safe_metadata' => '{"revision":1,"private":"never-expose-this"}']);
}
$connection->insert('submission_notes', ['submission_id' => $historyOther->id, 'created_by' => 1, 'created_at' => '2026-09-27 10:00:00', 'body' => 'Foreign history sentinel']);
$historyFirst = $historyExplorer->detail(1, $historyForm, $historyResponse->id);
$historyCursors = [];
foreach (array_keys($historyIds) as $kind) {
    if (count($historyFirst[$kind]) !== 100 || !$historyFirst[$kind . '_has_more']) { throw new RuntimeException('History first page is not bounded.'); }
    $historyCursors[$kind] = $historyFirst[$kind . '_next_before'];
}
$connection->insert('submission_notes', ['submission_id' => $historyResponse->id, 'created_by' => 1, 'created_at' => '2026-09-27 10:00:00', 'body' => 'Newer note excluded by cursor']);
$historyNext = $historyExplorer->detail(1, $historyForm, $historyResponse->id, history: $historyCursors);
foreach ($historyIds as $kind => $expectedIds) {
    $actual = array_merge(array_map('intval', array_column($historyFirst[$kind], 'id')), array_map('intval', array_column($historyNext[$kind], 'id')));
    if ($actual !== array_reverse($expectedIds) || count($historyNext[$kind]) !== 5 || $historyNext[$kind . '_next_before'] !== null) { throw new RuntimeException('History cursor skipped or repeated rows.'); }
}
if (str_contains(json_encode([$historyFirst, $historyNext]), 'never-expose-this') || $historyFirst['consents'] !== []) { throw new RuntimeException('History disclosed metadata or masked consent.'); }
$historyRestricted = $historyExplorer->detail(2, $historyForm, $historyResponse->id, history: $historyCursors);
if ($historyRestricted['audit'] !== [] || $historyRestricted['audit_next_before'] !== null) { throw new RuntimeException('History cursor bypassed audit ACL.'); }
foreach ([['notes' => '3junk'], ['notes' => 0], ['notes' => '999999999999999999999'], ['other' => 1]] as $invalidHistory) {
    try { $historyExplorer->detail(1, $historyForm, $historyResponse->id, history: $invalidHistory); throw new RuntimeException('Invalid history cursor accepted.'); } catch (InvalidArgumentException) {}
}
try { $historyExplorer->detail(1, $historyForm + 999999, $historyResponse->id, history: $historyCursors); throw new RuntimeException('Cross-form history leaked.'); } catch (OutOfBoundsException) {}
$historyRevealed = $historyExplorer->detail(1, $historyForm, $historyResponse->id, true);
if ($historyRevealed['consents'][$consentField]['text'] !== 'Exact historical consent <script>' || $historyRevealed['consents'][$consentField]['form_version_id'] !== $historyVersion) { throw new RuntimeException('Authorized historical consent lost snapshot.'); }
$declined = $historyExplorer->detail(1, $historyForm, $historyOther->id, true)['consents'][$consentField];
$declinedRow = $submissions->get($historyForm, $historyOther->id);
if ($declined['accepted'] !== false || $declined['text'] !== 'Exact historical consent <script>' || $declined['form_version_id'] !== $historyVersion || $declined['received_at'] !== substr($declinedRow['received_at'], 0, 19)) { throw new RuntimeException('Declined consent lost its exact historical evidence.'); }

// Remove the original consent entirely and publish an unrelated replacement.
// Historical interpretation must not depend on the current field graph.
$beforeHistorical = $historyReader->read($historyForm, $historyResponse->id, 1, revealSensitive: true);
$replacement = Nicode\FormStudio\Domain\Uuid::create();
$historyDraft['elements'] = [['uuid' => $replacement, 'type' => 'field']];
$historyDraft['fields'] = [['uuid' => $replacement, 'name' => 'replacement', 'type' => 'text', 'config' => ['label' => 'Current replacement label']]];
$replacementRevision = $forms->saveDraft($historyForm, (int) $forms->get($historyForm)['draft_revision'], $historyDraft, 1);
$replacementVersion = $forms->publish($historyForm, $replacementRevision, 1);
$afterHistorical = $historyReader->read($historyForm, $historyResponse->id, 1, revealSensitive: true);
if ($afterHistorical !== $beforeHistorical || $afterHistorical['form_version_id'] === $replacementVersion || str_contains(json_encode($afterHistorical), 'Current replacement label')) { throw new RuntimeException('Deleting a field from the new publication rewrote historical interpretation.'); }
$maskedHistorical = $historyReader->read($historyForm, $historyResponse->id, 2);
if ($maskedHistorical['values'] !== [] || $maskedHistorical['consents'] !== [] || $maskedHistorical['masked'] !== [$consentField]) { throw new RuntimeException('Removing a sensitive field from the current version unmasked old answers.'); }
$historicalBatch = $historyReader->readBatch($historyForm, [$historyResponse->id, $historyOther->id], 1, revealSensitive: true);
if ($historicalBatch[$historyResponse->id]['consents'][$consentField]['accepted'] !== true || $historicalBatch[$historyOther->id]['consents'][$consentField]['accepted'] !== false) { throw new RuntimeException('Batch historical read changed removed-field consent states.'); }
echo "Historical removed fields: new publication replacement preserves exact old values/layout/consent/version and old sensitive permissions in individual/batch reads.\n";
echo "Response history: independent bounded keyset pages, concurrent insert stability, exact coverage, scope/ACL, metadata filtering and explicit consent reveal passed.\n";
require __DIR__ . '/database-history-provider.php';
