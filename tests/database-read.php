<?php
declare(strict_types=1);

$readForm = $forms->create('Sensitive read fixture', 'reader-' . bin2hex(random_bytes(5)), 1);
$readDraft = $forms->draft($readForm); $secretField = Nicode\FormStudio\Domain\Uuid::create(); $fileField = Nicode\FormStudio\Domain\Uuid::create();
$readDraft['elements'] = [['uuid' => $secretField, 'type' => 'field', 'parent_uuid' => null], ['uuid' => $fileField, 'type' => 'field', 'parent_uuid' => null]];
$readDraft['fields'] = [['uuid' => $secretField, 'name' => 'private', 'type' => 'text', 'sensitive' => true, 'include_export' => true, 'config' => []], ['uuid' => $fileField, 'name' => 'attachment', 'type' => 'file', 'sensitive' => true, 'config' => ['extensions' => ['txt'], 'mime_types' => ['text/plain'], 'max_bytes' => 1000]]];
$publicReadField = Nicode\FormStudio\Domain\Uuid::create();
$readDraft['elements'][] = ['uuid' => $publicReadField, 'type' => 'field', 'parent_uuid' => null];
$readDraft['fields'][] = ['uuid' => $publicReadField, 'name' => 'public_answer', 'type' => 'text', 'config' => []];
$readRevision = $forms->saveDraft($readForm, 0, $readDraft, 1); $readVersion = $forms->publish($readForm, $readRevision, 1); $readSpec = $forms->version($readForm, $readVersion);
$fileUuid = Nicode\FormStudio\Domain\Uuid::create();
$readSubmission = $submissions->persist($readForm, $readVersion, $readSpec, [$publicReadField => 'public value', $secretField => 'restricted value', $fileField => ['uuid' => $fileUuid, 'name' => 'private.txt', 'mime' => 'text/plain', 'size' => 6]], hash('sha256', random_bytes(32)));
$authorizeRead = static fn (int $actor, int $form, string $permission): bool => $actor === 1 || ($actor === 2 && in_array($permission, ['formstudio.submissions.view', 'formstudio.submissions.export'], true));
$reader = new Nicode\FormStudio\Application\SubmissionReader($submissions, $forms, $connection, $authorizeRead);
$masked = $reader->read($readForm, $readSubmission->id, 2);
if ($masked['values'] !== [$publicReadField => 'public value'] || count($masked['masked']) !== 2) { throw new RuntimeException('Sensitive detail data leaked.'); }
try { $reader->read($readForm, $readSubmission->id, 2, revealSensitive: true); throw new RuntimeException('Sensitive reveal permission bypass.'); } catch (DomainException) {}
if ($reader->read($readForm, $readSubmission->id, 1, revealSensitive: true)['values'][$secretField] !== 'restricted value') { throw new RuntimeException('Authorized reveal failed.'); }
if ($reader->read($readForm, $readSubmission->id, 2, 'export')['values'] !== [$publicReadField => 'public value']) { throw new RuntimeException('Sensitive export leaked.'); }
$readAudits = $connection->rows('SELECT actor_id, safe_metadata FROM ' . $connection->table('audit_log') . ' WHERE submission_uuid = :uuid AND event_type = :event', [':uuid' => $readSubmission->uuid, ':event' => 'submission.reveal_sensitive']);
if (count($readAudits) !== 1 || (int) $readAudits[0]['actor_id'] !== 1 || json_decode($readAudits[0]['safe_metadata'], true) !== ['fields' => 2, 'request_metadata_items' => 0]) { throw new RuntimeException('Reveal audit counted public data or recorded denied/ordinary reads.'); }
$stream = fopen('php://temp', 'w+b'); fwrite($stream, 'secret'); rewind($stream); $readObject = $privateStorage->put($stream, 100); fclose($stream);
$connection->insert('submission_files', ['uuid' => $fileUuid, 'submission_id' => $readSubmission->id, 'field_uuid' => $fileField, 'provider' => 'local', 'storage_key' => $readObject->key, 'original_name' => 'private.txt', 'mime' => 'text/plain', 'size_bytes' => $readObject->size, 'checksum' => $readObject->checksum, 'created_at' => gmdate('Y-m-d H:i:s')]);
$downloads = new Nicode\FormStudio\Application\FileDownloads($connection, $forms, $storageProviders, $authorizeRead);
$unavailableStorageDownloads = new Nicode\FormStudio\Application\FileDownloads($connection, $forms, new Nicode\FormStudio\Registry\StorageProviderRegistry(), $authorizeRead);
try { $unavailableStorageDownloads->open($readForm, $fileUuid, 1); throw new RuntimeException('Missing storage provider allowed a download.'); }
catch (OutOfBoundsException $unavailable) { if ($unavailable->getMessage() !== 'File unavailable.') { throw new RuntimeException('Missing storage provider disclosed internal details.'); } }
try { $downloads->open($readForm, $fileUuid, 2); throw new RuntimeException('Sensitive file download bypass.'); } catch (OutOfBoundsException) {}
try { $downloads->open($multiForm, $fileUuid, 1); throw new RuntimeException('Cross-form file download bypass.'); } catch (OutOfBoundsException) {}
$download = $downloads->open($readForm, $fileUuid, 1); $bytes = stream_get_contents($download->stream); fclose($download->stream);
if ($bytes !== 'secret' || $download->headers['Cache-Control'] !== 'private, no-store' || $download->headers['Content-Type'] !== 'application/octet-stream') { throw new RuntimeException('Safe private download failed.'); }
// Sensitivity belongs to each immutable response version, in both directions.
$readDraft['fields'][0]['sensitive'] = $readDraft['fields'][1]['sensitive'] = false;
$publicRevision = $forms->saveDraft($readForm, (int) $forms->get($readForm)['draft_revision'], $readDraft, 1);
$publicVersion = $forms->publish($readForm, $publicRevision, 1);
$publicSubmission = $submissions->persist($readForm, $publicVersion, $forms->version($readForm, $publicVersion), [$secretField => 'later public answer'], hash('sha256', random_bytes(32)));
if ($reader->read($readForm, $readSubmission->id, 2)['values'] !== [$publicReadField => 'public value']) { throw new RuntimeException('Current public policy exposed historical sensitive answers.'); }
try { $downloads->open($readForm, $fileUuid, 2); throw new RuntimeException('Current public policy exposed historical sensitive file.'); } catch (OutOfBoundsException) {}
$readDraft['fields'][0]['sensitive'] = $readDraft['fields'][1]['sensitive'] = true;
$privateRevision = $forms->saveDraft($readForm, (int) $forms->get($readForm)['draft_revision'], $readDraft, 1);
$forms->publish($readForm, $privateRevision, 1);
if ($reader->read($readForm, $publicSubmission->id, 2)['values'] !== [$secretField => 'later public answer']) { throw new RuntimeException('Later policy rewrote historical public meaning.'); }
$historicalAudit = $connection->rows('SELECT id FROM ' . $connection->table('audit_log') . ' WHERE submission_uuid = :uuid', [':uuid' => $publicSubmission->uuid]);
if ($historicalAudit !== []) { throw new RuntimeException('Historical ordinary read produced a false sensitive reveal event.'); }
// Removing the current file field must neither orphan its historical attachment
// nor make the historical sensitive policy disappear.
$readDraft['elements'] = array_values(array_filter($readDraft['elements'], static fn (array $element): bool => $element['uuid'] !== $fileField));
$readDraft['fields'] = array_values(array_filter($readDraft['fields'], static fn (array $field): bool => $field['uuid'] !== $fileField));
$removedRevision = $forms->saveDraft($readForm, (int) $forms->get($readForm)['draft_revision'], $readDraft, 1);
$forms->publish($readForm, $removedRevision, 1);
try { $downloads->open($readForm, $fileUuid, 2); throw new RuntimeException('Removed field lost historical sensitive download policy.'); } catch (OutOfBoundsException) {}
$historicalDownload = $downloads->open($readForm, $fileUuid, 1);
try { if (stream_get_contents($historicalDownload->stream) !== 'secret') { throw new RuntimeException('Removing current field orphaned historical file bytes.'); } }
finally { fclose($historicalDownload->stream); }
$downloadAllowed = true;
$revocableDownloads = new Nicode\FormStudio\Application\FileDownloads($connection, $forms, $storageProviders, static function (int $actor, int $form, string $permission) use (&$downloadAllowed): bool { return $downloadAllowed; });
$authorizedDownload = $revocableDownloads->open($readForm, $fileUuid, 1); fclose($authorizedDownload->stream);
$downloadAllowed = false;
$auditsBeforeDenial = $connection->rows('SELECT id FROM ' . $connection->table('audit_log') . " WHERE submission_uuid = :uuid AND event_type = 'submission.file_download'", [':uuid' => $readSubmission->uuid]);
try { $revocableDownloads->open($readForm, $fileUuid, 1); throw new RuntimeException('Revoked permission still authorizes historical download.'); } catch (OutOfBoundsException) {}
$auditsAfterDenial = $connection->rows('SELECT id FROM ' . $connection->table('audit_log') . " WHERE submission_uuid = :uuid AND event_type = 'submission.file_download'", [':uuid' => $readSubmission->uuid]);
if ($auditsAfterDenial !== $auditsBeforeDenial) { throw new RuntimeException('Denied download recorded a successful file access.'); }
echo "Historical files: removed current field preserves exact bytes and sensitive policy; permission revocation denies subsequent access without success audit.\n";
$maintenance->apply($readForm, $readSubmission->id, 1, 'anonymize'); $worker->tick(2);
try { $downloads->open($readForm, $fileUuid, 1); throw new RuntimeException('Anonymized file download survived.'); } catch (OutOfBoundsException) {}
echo "Sensitive detail/export masking, explicit reveal and scoped private downloads verified.\n";
