<?php
declare(strict_types=1);
$retainedForm = $forms->create('Historical retention fixture', 'retention-' . bin2hex(random_bytes(5)), 1);
$retainedDraft = $forms->draft($retainedForm); $retainedField = Nicode\FormStudio\Domain\Uuid::create();
$retainedDraft['elements'] = [['uuid' => $retainedField, 'type' => 'field']]; $retainedDraft['fields'] = [['uuid' => $retainedField, 'type' => 'text', 'name' => 'answer', 'config' => []]];
$retainedIds = []; $retainedVersions = [];
foreach (['anonymize', 'delete'] as $operation) {
    $retainedDraft['privacy'] = ['retention' => ['action' => $operation, 'amount' => 1, 'unit' => 'days']];
    $savedRevision = $forms->saveDraft($retainedForm, (int) $forms->get($retainedForm)['draft_revision'], $retainedDraft, 1);
    $retainedVersion = $forms->publish($retainedForm, $savedRevision, 1); $retainedVersions[$operation] = $retainedVersion;
    $retainedSpec = $forms->version($retainedForm, $retainedVersion);
    foreach (['2000-01-01 00:00:00', '2000-01-01 00:00:00', '2099-01-01 00:00:00'] as $expiry) {
        $retainedIds[$operation][] = $submissions->persist($retainedForm, $retainedVersion, $retainedSpec, [$retainedField => 'retained test value'], hash('sha256', random_bytes(32)), ['expires_at' => $expiry])->id;
    }
}
$dispatch = new Nicode\FormStudio\Jobs\RetentionDispatchHandler($connection, $forms, $jobs); $handlerRegistry->register($dispatch);
$dispatchId = $jobs->enqueue('retention-dispatch', [], 0);
// Keep this one-row cursor fixture independent of expired responses from earlier runs.
$connection->execute('UPDATE ' . $connection->table('jobs') . ' SET cursor_data = :cursor WHERE id = :id', [':cursor' => json_encode(['expires_at' => '2000-01-01 00:00:00', 'id' => $retainedIds['anonymize'][0] - 1, 'cutoff' => '2000-01-01 00:00:00'], JSON_THROW_ON_ERROR), ':id' => $dispatchId]);
for ($chunk = 0; $chunk < 40 && $atomicWorker->tick(1); $chunk++) {}
if ($jobs->get($dispatchId)['state'] !== 'completed') { throw new RuntimeException('Retention dispatcher did not finish.'); }
foreach (array_slice($retainedIds['anonymize'], 0, 2) as $responseId) { if ($submissions->get($retainedForm, $responseId)['anonymized_at'] === null) { throw new RuntimeException('Historical anonymization policy lost.'); } }
foreach (array_slice($retainedIds['delete'], 0, 2) as $responseId) { try { $submissions->get($retainedForm, $responseId); throw new RuntimeException('Historical delete policy lost.'); } catch (OutOfBoundsException) {} }
foreach ($retainedIds as $ids) { if ($submissions->get($retainedForm, $ids[2])['anonymized_at'] !== null) { throw new RuntimeException('Retention touched an unexpired response.'); } }
$retainedJobs = $connection->rows('SELECT parameters, creator_id, state FROM ' . $connection->table('jobs') . " WHERE form_id = :form AND job_type = 'retention'", [':form' => $retainedForm]);
if (count($retainedJobs) !== 2) { throw new RuntimeException('Retention dispatch duplicated version jobs across chunks.'); }
foreach ($retainedJobs as $retainedJob) { if ((int) $retainedJob['creator_id'] !== 1 || $retainedJob['state'] !== 'completed') { throw new RuntimeException('Retention lost historical publisher authority.'); } }
echo "Retention scheduling: expiry cursor, original version policy, publisher authority, deduplicated dispatch and unexpired-response isolation verified.\n";
$retentionAclForm = $formAdministration->create('Retention ACL fixture', 'retention-acl-' . bin2hex(random_bytes(4)), 731);
$retentionAclDraft = $formAdministration->edit($retentionAclForm, 731)['draft'];
$retentionAclDraft['elements'] = $retainedDraft['elements']; $retentionAclDraft['fields'] = $retainedDraft['fields'];
$retentionAclDraft['privacy'] = ['retention' => ['action' => 'delete', 'amount' => 1, 'unit' => 'days']];
$retentionAclRevision = $formAdministration->save($retentionAclForm, 0, $retentionAclDraft, 731);
$adminDenied = ['formstudio.submissions.delete'];
try { $formAdministration->publish($retentionAclForm, $retentionAclRevision, 731); throw new RuntimeException('Publisher configured deletion without its permission.'); } catch (DomainException) {}
if ($forms->get($retentionAclForm)['published_version_id'] !== null) { throw new RuntimeException('Unauthorized retention publication left an active snapshot.'); }
$adminDenied = [];
