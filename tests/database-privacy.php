<?php
declare(strict_types=1);

$maintenance = new Nicode\FormStudio\Infrastructure\Database\SubmissionMaintenance($connection, $jobs, static fn (int $actor, int $form, string $permission): bool => $actor === 1);
$privacyAttempt = hash('sha256', random_bytes(32));
$privateSubmission = $submissions->persist($multiForm, $multiVersion, $multiSpec, [$multiField => ['a']], $privacyAttempt, ['user_id' => 42]);
$storageRoot = $root . '/build/maintenance-storage'; if (!is_dir($storageRoot)) { mkdir($storageRoot, 0770, true); }
$privateStorage = new Nicode\FormStudio\Storage\LocalStorage($storageRoot, $root . '/build/joomla-6.0.0');
$stream = fopen('php://temp', 'w+b'); fwrite($stream, 'private content'); rewind($stream); $stored = $privateStorage->put($stream, 100); fclose($stream);
$connection->insert('submission_files', ['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'submission_id' => $privateSubmission->id, 'field_uuid' => $multiField, 'provider' => 'local', 'storage_key' => $stored->key, 'original_name' => 'private-name.txt', 'mime' => 'text/plain', 'size_bytes' => $stored->size, 'checksum' => $stored->checksum, 'created_at' => gmdate('Y-m-d H:i:s')]);
try { $maintenance->apply($multiForm, $privateSubmission->id, 2, 'anonymize'); throw new RuntimeException('Maintenance permission bypass.'); } catch (DomainException) {}
if (!$maintenance->apply($multiForm, $privateSubmission->id, 1, 'anonymize') || $maintenance->apply($multiForm, $privateSubmission->id, 1, 'anonymize')) { throw new RuntimeException('Anonymization idempotence failed.'); }
$anonymized = $submissions->get($multiForm, $privateSubmission->id);
if ($anonymized['user_id'] !== null || json_decode($anonymized['canonical_payload'], true)['values'] !== [] || !$anonymized['anonymized_at']) { throw new RuntimeException('Anonymization retained payload or user link.'); }
if ($connection->rows('SELECT id FROM ' . $connection->table('submission_files') . ' WHERE submission_id = :id', [':id' => $privateSubmission->id]) !== []) { throw new RuntimeException('Anonymized files remain downloadable.'); }
try { $submissions->persist($multiForm, $multiVersion, $multiSpec, [$multiField => ['a']], $privacyAttempt, ['user_id' => 42]); throw new RuntimeException('Erased attempt reintroduced data.'); } catch (DomainException) {}
$storageProviders = new Nicode\FormStudio\Registry\StorageProviderRegistry(); $storageProviders->register($privateStorage);
$handlerRegistry->register(new Nicode\FormStudio\Jobs\FileCleanupHandler($storageProviders, $jobs)); $worker->tick(2);
if ($privateStorage->exists($stored->key)) { throw new RuntimeException('Owned object cleanup did not run.'); }
$expired = $submissions->persist($multiForm, $multiVersion, $multiSpec, [$multiField => ['b']], hash('sha256', random_bytes(32)), ['expires_at' => '2000-01-01 00:00:00']);
$handlerRegistry->register(new Nicode\FormStudio\Jobs\RetentionHandler($connection, $jobs, $maintenance, static fn (int $actor, int $form, string $permission): bool => $actor === 1));
$retentionId = $jobs->enqueue('retention', ['form_id' => $multiForm, 'operation' => 'delete'], 1); $worker->tick(2);
if ($jobs->get($retentionId)['state'] !== 'completed') { throw new RuntimeException('Retention job failed.'); }
try { $submissions->get($multiForm, $expired->id); throw new RuntimeException('Expired response survived retention.'); } catch (OutOfBoundsException) {}
foreach ([$privateSubmission->uuid => 'submission.anonymize', $expired->uuid => 'submission.delete'] as $reference => $event) {
    $privacyAudit = $connection->rows('SELECT actor_id, event_type FROM ' . $connection->table('audit_log') . ' WHERE submission_uuid = :uuid', [':uuid' => $reference]);
    if (count($privacyAudit) !== 1 || (int) $privacyAudit[0]['actor_id'] !== 1 || $privacyAudit[0]['event_type'] !== $event) { throw new RuntimeException('Privacy audit lost identity, duplicated idempotent work or recorded denied work.'); }
}
echo "Anonymization, erased-attempt protection, private object cleanup and retention verified.\n";
