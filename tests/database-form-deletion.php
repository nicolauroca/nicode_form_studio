<?php
declare(strict_types=1);

$deleteForm = $forms->create('Permanent deletion fixture', 'delete-' . bin2hex(random_bytes(5)), 1);
$deleteDraft = $forms->draft($deleteForm); $deleteField = Nicode\FormStudio\Domain\Uuid::create();
$deleteDraft['elements'] = [['uuid' => $deleteField, 'type' => 'field']];
$deleteDraft['fields'] = [['uuid' => $deleteField, 'type' => 'text', 'name' => 'answer', 'index' => true, 'config' => ['max_length' => 255]]];
$deleteRevision = $forms->saveDraft($deleteForm, 0, $deleteDraft, 1);
$deleteVersion = $forms->publish($deleteForm, $deleteRevision, 1); $deleteSpec = $forms->version($deleteForm, $deleteVersion);
$deleteResponses = [];
for ($i = 0; $i < 3; $i++) { $deleteResponses[] = $submissions->persist($deleteForm, $deleteVersion, $deleteSpec, [$deleteField => 'Private delete fixture'], hash('sha256', random_bytes(32))); }
$deletionAllowed = true;
$deletePermission = static function (int $actor, ?int $form, string $permission) use (&$deletionAllowed): bool { return $actor === 1 && $deletionAllowed; };
$deleteJobs = new Nicode\FormStudio\Infrastructure\Database\JobRepository($connection);
$deletion = new Nicode\FormStudio\Application\FormDeletion($connection, $deleteJobs, $deletePermission);
try { $deletion->review($deleteForm, 1); throw new RuntimeException('Published form accepted for deletion.'); } catch (DomainException) {}
$deleteRevision = $forms->deactivate($deleteForm, 2, 1, 'trashed');
$stream = fopen('php://temp', 'w+b'); fwrite($stream, 'owned deletion file'); rewind($stream); $deleteFile = $privateStorage->put($stream, 100); fclose($stream);
$connection->insert('submission_files', ['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'submission_id' => $deleteResponses[0]->id, 'field_uuid' => $deleteField, 'provider' => 'local', 'storage_key' => $deleteFile->key, 'original_name' => 'delete.txt', 'mime' => 'text/plain', 'size_bytes' => $deleteFile->size, 'checksum' => $deleteFile->checksum, 'created_at' => gmdate('Y-m-d H:i:s')]);
$review = $deletion->review($deleteForm, 1);
if ($review['responses'] !== 3 || $review['files'] !== 1 || $review['bytes'] !== $deleteFile->size) { throw new RuntimeException('Deletion review counts mismatch.'); }
foreach ([[2, $review['uuid']], [1, Nicode\FormStudio\Domain\Uuid::create()]] as [$actor, $confirmation]) {
    try { $deletion->enqueue($deleteForm, $deleteRevision, $actor, $confirmation); throw new RuntimeException('Unconfirmed/unauthorized deletion accepted.'); } catch (DomainException) {}
}
try { $deletion->enqueue($deleteForm, 0, 1, $review['uuid']); throw new RuntimeException('Stale deletion accepted.'); } catch (Nicode\FormStudio\Domain\ConcurrentEdit) {}
$cancelledJob = $deleteJobs->enqueue('reindex', ['form_id' => $deleteForm], 1);
$deleteRuns = new Nicode\FormStudio\Infrastructure\Database\ActionRunRepository($connection);
$activeAction = $deleteRuns->claim($deleteResponses[0]->id, Nicode\FormStudio\Domain\Uuid::create(), 'test');
$deleteJob = $deletion->enqueue($deleteForm, $deleteRevision, 1, $review['uuid']); $deleteRevision++;
if ($deleteJobs->get($cancelledJob)['state'] !== 'cancelled' || $deletion->enqueue($deleteForm, $deleteRevision, 1, $review['uuid']) !== $deleteJob) { throw new RuntimeException('Deletion failed to fence jobs or deduplicate.'); }
foreach ([static fn () => $forms->publish($deleteForm, $deleteRevision, 1), static fn () => $forms->deactivate($deleteForm, $deleteRevision, 1, 'unpublished'), static fn () => $forms->saveDraft($deleteForm, $deleteRevision, $deleteDraft, 1), static fn () => $submissions->persist($deleteForm, $deleteVersion, $deleteSpec, [$deleteField => 'late'], hash('sha256', random_bytes(32)))] as $mutation) {
    try { $mutation(); throw new RuntimeException('Deleting form accepted a mutation.'); } catch (DomainException|Nicode\FormStudio\Domain\ConcurrentEdit) {}
}
if ($deleteRuns->claim($deleteResponses[1]->id, Nicode\FormStudio\Domain\Uuid::create(), 'test') !== null) { throw new RuntimeException('Deleting form accepted a new action.'); }
$deletedAssets = [];
$deletePrivacy = new Nicode\FormStudio\Infrastructure\Database\SubmissionMaintenance($connection, $deleteJobs, $deletePermission);
$deleteHandler = new Nicode\FormStudio\Jobs\FormDeleteHandler($connection, $deleteJobs, $deletePrivacy, $deletion, static function (int $form) use (&$deletedAssets): void { $deletedAssets[] = $form; });
$claimDeletionJob = static function (int $jobId) use ($connection, $deleteJobs): Nicode\FormStudio\Jobs\JobLease {
    $connection->execute('UPDATE ' . $connection->table('jobs') . " SET available_at = '1000-01-01 00:00:00' WHERE id = :id", [':id' => $jobId]);
    $lease = $deleteJobs->claim();
    if ($lease?->id !== $jobId) { throw new RuntimeException('Dedicated deletion job was not claimed.'); }
    return $lease;
};
$deleteLease = $claimDeletionJob($deleteJob);
$deletionAllowed = false;
try { $connection->transaction(static fn () => $deleteHandler->run($deleteLease, 2)); throw new RuntimeException('Revoked deletion permission ignored.'); } catch (DomainException) {}
$deletionAllowed = true;
try { $connection->transaction(static fn () => $deleteHandler->run($deleteLease, 2)); throw new RuntimeException('Running action was erased.'); }
catch (Nicode\FormStudio\Jobs\RetryableJobFailure) { $deleteJobs->retry($deleteLease, 'form_actions_running', 1); }
$deleteRuns->finish($activeAction, 'succeeded', 'ok');
$deleteLease = $claimDeletionJob($deleteJob);
try {
    $connection->transaction(function () use ($connection, $deleteHandler, $deleteJobs, $deleteLease): void {
        $progress = $deleteHandler->run($deleteLease, 2);
        $connection->execute('UPDATE ' . $connection->table('jobs') . " SET lease_until = '2000-01-01 00:00:00' WHERE id = :id", [':id' => $deleteLease->id]);
        $deleteJobs->checkpoint($deleteLease, $progress);
    });
    throw new RuntimeException('Lost deletion checkpoint committed.');
} catch (Nicode\FormStudio\Jobs\LeaseLost) {}
if (!$submissions->get($deleteForm, $deleteResponses[0]->id) || !$privateStorage->exists($deleteFile->key)) { throw new RuntimeException('Deletion rollback lost response/file.'); }
$connection->transaction(function () use ($deleteHandler, $deleteJobs, $deleteLease): void { $deleteJobs->checkpoint($deleteLease, $deleteHandler->run($deleteLease, 2)); });
if ((int) $connection->row('SELECT COUNT(*) AS total FROM ' . $connection->table('submissions') . ' WHERE form_id = :form', [':form' => $deleteForm])['total'] !== 1) { throw new RuntimeException('Deletion ignored the chunk limit.'); }
for ($chunk = 0; $chunk < 50 && $deleteJobs->get($deleteJob)['state'] !== 'completed'; $chunk++) {
    $lease = $claimDeletionJob($deleteJob);
    $connection->transaction(function () use ($deleteHandler, $deleteJobs, $lease): void { $deleteJobs->checkpoint($lease, $deleteHandler->run($lease, 2)); });
}
if ($deleteJobs->get($deleteJob)['state'] !== 'completed' || $deletedAssets !== [$deleteForm] || $connection->row('SELECT id FROM ' . $connection->table('forms') . ' WHERE id = :id', [':id' => $deleteForm])) { throw new RuntimeException('Form deletion did not finish.'); }
$cleanupJob = $connection->row('SELECT id FROM ' . $connection->table('jobs') . " WHERE job_type = 'file-cleanup' AND parameters LIKE :key ORDER BY id DESC LIMIT 1", [':key' => '%' . $deleteFile->key . '%']);
if (!$cleanupJob || !$privateStorage->exists($deleteFile->key)) { throw new RuntimeException('Deletion lost durable file cleanup.'); }
$cleanupLease = $claimDeletionJob((int) $cleanupJob['id']);
$cleanup = new Nicode\FormStudio\Jobs\FileCleanupHandler($storageProviders, $deleteJobs);
$deleteJobs->checkpoint($cleanupLease, $cleanup->run($cleanupLease, 2));
if ($privateStorage->exists($deleteFile->key) || !$forms->get($id)) { throw new RuntimeException('Form deletion affected unrelated data or retained owned bytes.'); }
$deletionAudit = $connection->rows('SELECT safe_metadata FROM ' . $connection->table('audit_log') . " WHERE form_id = :form AND event_type = 'form.delete'", [':form' => $deleteForm]);
if (count($deletionAudit) !== 1 || json_decode($deletionAudit[0]['safe_metadata'], true, 32, JSON_THROW_ON_ERROR)['job_id'] !== $deleteJob) { throw new RuntimeException('Permanent deletion audit was erased or duplicated.'); }
echo "Permanent form deletion: confirmed ACL/revision, bounded chunks, admission/action fences, lease rollback, native-asset callback and durable owned-file cleanup passed.\n";
