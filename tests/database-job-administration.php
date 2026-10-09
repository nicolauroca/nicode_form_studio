<?php
declare(strict_types=1);
$jobAdmin = new Nicode\FormStudio\Application\JobAdministration($connection, $forms, $jobs, $handlerRegistry, $search, $bulkPermission);
$adminJobId = $jobAdmin->enqueue(1, $bulkForm, 'export-csv', ['fields' => [$bulkField], 'creator_id' => 99, 'version_id' => 9999]);
$adminJob = $jobs->get($adminJobId); $adminParameters = json_decode($adminJob['parameters'], true, 512, JSON_THROW_ON_ERROR);
if ((int) $adminJob['creator_id'] !== 1 || $adminParameters['version_id'] !== $bulkVersion || $adminParameters['query']['filters']['form_id'] !== $bulkForm) { throw new RuntimeException('Job request forged its trusted identity/schema/scope.'); }
foreach (['file-cleanup', 'export-cleanup', 'retention', 'test-atomic-rollback'] as $privateType) {
    try { $jobAdmin->enqueue(1, $bulkForm, $privateType, []); throw new RuntimeException('Private handler exposed to HTTP composition.'); } catch (InvalidArgumentException) {}
}
try { $jobAdmin->enqueue(1, $bulkForm, 'export-csv', ['fields' => [$bulkField], 'query' => ['filters' => ['form_id' => $bulkForm + 1], 'fields' => []]]); throw new RuntimeException('Cross-form query accepted.'); } catch (InvalidArgumentException) {}
try { $jobAdmin->enqueue(2, $bulkForm, 'reindex', []); throw new RuntimeException('Denied actor enqueued job.'); } catch (DomainException) {}
$ownerOnly = new Nicode\FormStudio\Application\JobAdministration($connection, $forms, $jobs, $handlerRegistry, $search, static fn (int $actor, ?int $form, string $permission): bool => $permission === 'core.manage');
if ($ownerOnly->listing(2)['can_run'] || $ownerOnly->listing(2)['rows'] === []) { throw new RuntimeException('Owner listing or worker capability failed.'); }
foreach ($ownerOnly->listing(2)['rows'] as $row) { if ((int) $row['creator_id'] !== 2) { throw new RuntimeException('Foreign job listing disclosure.'); } }
try { $ownerOnly->record(2, $adminJobId); throw new RuntimeException('Foreign job detail disclosure.'); } catch (OutOfBoundsException) {}
try { $ownerOnly->cancel(2, $adminJobId); throw new RuntimeException('Foreign job cancellation accepted.'); } catch (OutOfBoundsException) {}
$publicJob = $jobAdmin->record(1, $adminJobId);
foreach (['parameters', 'cursor_data', 'lease_token', 'artifact_key'] as $privateColumn) { if (array_key_exists($privateColumn, $publicJob)) { throw new RuntimeException('Job private internals exposed.'); } }
if (!$jobAdmin->cancel(1, $adminJobId) || $jobAdmin->cancel(1, $adminJobId)) { throw new RuntimeException('Cancellation terminal-state semantics failed.'); }
echo "Job administration: trusted creator/schema, restricted handler types, scoped queries, private status and owner cancellation verified.\n";
