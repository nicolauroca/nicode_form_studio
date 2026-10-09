<?php
declare(strict_types=1);

$exportRoot = $root . '/build/export-workspace'; if (!is_dir($exportRoot)) { mkdir($exportRoot, 0770, true); }
$exportWorkspace = new Nicode\FormStudio\Export\ExportWorkspace($exportRoot, $root . '/build/joomla-6.0.0');
$exportHandler = new Nicode\FormStudio\Jobs\ExportHandler($connection, $forms, $reader, $jobs, $exportWorkspace, $authorizeRead, $search);
$handlerRegistry->register($exportHandler);
$exportId = $jobs->enqueue('export-csv', ['form_id' => $multiForm, 'version_id' => $multiVersion, 'fields' => [$multiField], 'include_sensitive' => false], 1);
$firstExportLease = $jobs->claim(); if ($firstExportLease->id !== $exportId) { throw new RuntimeException('Unexpected export claim.'); }
$exportHandler->run($firstExportLease, 2); // Simulate a crash after writing, before checkpoint.
$testNow += 61;
for ($chunk = 0; $chunk < 20 && $jobs->get($exportId)['state'] !== 'completed'; $chunk++) { $worker->tick(2); }
$exportJob = $jobs->get($exportId);
if ($exportJob['state'] !== 'completed') { throw new RuntimeException('Export did not complete in bounded chunks.'); }
$exportAudit = $connection->rows('SELECT actor_id, event_type FROM ' . $connection->table('audit_log') . ' WHERE correlation_id = :uuid', [':uuid' => $exportJob['uuid']]);
if (count($exportAudit) !== 1 || (int) $exportAudit[0]['actor_id'] !== 1 || $exportAudit[0]['event_type'] !== 'submission.export') { throw new RuntimeException('Export completion audit lost its job correlation or actor.'); }
$exportDownloads = new Nicode\FormStudio\Application\ExportDownloads($jobs, $exportWorkspace, $authorizeRead);
try { $exportDownloads->open($exportId, 2); throw new RuntimeException('Other-user export download bypass.'); } catch (OutOfBoundsException) {}
$exportDownload = $exportDownloads->open($exportId, 1); $csvRows = [];
while (($csvRow = fgetcsv($exportDownload->stream, escape: '')) !== false) { $csvRows[] = $csvRow; } fclose($exportDownload->stream);
if (count($csvRows) !== (int) $exportJob['processed'] + 1 || $csvRows[0] !== ['reference', 'received_at', 'state', 'choices'] || count(array_unique(array_column(array_slice($csvRows, 1), 0))) !== (int) $exportJob['processed']) { throw new RuntimeException('Export retry duplicated rows or headers.'); }
$connection->execute('UPDATE ' . $connection->table('jobs') . ' SET expires_at = :past WHERE id = :id', [':past' => '2000-01-01 00:00:00', ':id' => $exportId]);
try { $exportDownloads->open($exportId, 1); throw new RuntimeException('Expired export download bypass.'); } catch (OutOfBoundsException) {}
$handlerRegistry->register(new Nicode\FormStudio\Jobs\ExportCleanupHandler($connection, $jobs, $exportWorkspace));
$cleanupId = $jobs->enqueue('export-cleanup', [], 1);
// Reused fixtures can contain more than one chunk of expired artifacts.
for ($cleanupChunk = 0; $cleanupChunk < 1000 && $jobs->get($cleanupId)['state'] !== 'completed'; $cleanupChunk++) { $worker->tick(2); }
if ($jobs->get($cleanupId)['state'] !== 'completed') { throw new RuntimeException('Export cleanup did not complete in bounded chunks.'); }
if ($jobs->get($exportId)['artifact_key'] !== null) { throw new RuntimeException('Expired export artifact was not invalidated.'); }
echo "Chunked export crash recovery, unique rows, download ACL and artifact expiration verified.\n";
$extraFiltered = $submissions->persist($multiForm, $multiVersion, $multiSpec, [$multiField => ['b']], hash('sha256', random_bytes(32)));
$filteredExportId = $jobs->enqueue('export-csv', ['form_id' => $multiForm, 'version_id' => $multiVersion, 'fields' => [$multiField], 'query' => ['sort' => 'id_asc', 'filters' => ['form_id' => $multiForm], 'fields' => [['field' => $multiField, 'operator' => 'equals', 'value' => 'b']]]], 1);
$worker->tick(2);
if ($jobs->get($filteredExportId)['state'] !== 'pending' || (int) $jobs->get($filteredExportId)['processed'] !== 2) { throw new RuntimeException('Filtered export failed to checkpoint first search page.'); }
$lateFiltered = $submissions->persist($multiForm, $multiVersion, $multiSpec, [$multiField => ['b']], hash('sha256', random_bytes(32)));
$worker->tick(2);
$filteredJob = $jobs->get($filteredExportId);
if ($filteredJob['state'] !== 'completed' || (int) $filteredJob['processed'] !== 3) { throw new RuntimeException('Filtered export included late responses or lost matches.'); }
$filteredDownload = $exportDownloads->open($filteredExportId, 1); $filteredRows = [];
while (($csvRow = fgetcsv($filteredDownload->stream, escape: '')) !== false) { $filteredRows[] = $csvRow; } fclose($filteredDownload->stream);
if (array_column(array_slice($filteredRows, 1), 0) !== [$mixed->uuid, $onlyB->uuid, $extraFiltered->uuid]) { throw new RuntimeException('Filtered export lost ascending search order or changed filter meaning.'); }
echo "Filtered CSV export: pinned schema, typed search cursor, stable order and high-water exclusion of later responses verified.\n";
$privacyExportId = $jobs->enqueue('export-csv', ['form_id' => $multiForm, 'version_id' => $multiVersion, 'fields' => [$multiField]], 1);
$worker->tick(500);
if ($jobs->get($privacyExportId)['state'] !== 'completed') { throw new RuntimeException('Privacy export fixture did not complete.'); }
$maintenance->apply($multiForm, $mixed->id, 1, 'anonymize');
if ($jobs->get($privacyExportId)['state'] !== 'cancelled') { throw new RuntimeException('Privacy action did not revoke existing export.'); }
try { $exportDownloads->open($privacyExportId, 1); throw new RuntimeException('Export containing erased response remained downloadable.'); } catch (OutOfBoundsException) {}
$worker->tick(500);
if ($jobs->get($privacyExportId)['artifact_key'] !== null) { throw new RuntimeException('Privacy export artifact cleanup failed.'); }
echo "Privacy operations revoke and remove existing form export artifacts.\n";
