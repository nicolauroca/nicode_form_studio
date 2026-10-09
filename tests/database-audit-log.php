<?php
declare(strict_types=1);
$auditCorrelation = Nicode\FormStudio\Domain\Uuid::create();
$auditViewer = new Nicode\FormStudio\Application\AuditLog($connection, static fn (int $actor, ?int $form, string $permission): bool => $actor === 1);
foreach (range(1, 102) as $index) {
    $connection->insert('audit_log', ['correlation_id' => $auditCorrelation, 'actor_id' => 731, 'event_type' => 'form.save', 'form_id' => $id, 'submission_uuid' => null, 'created_at' => '2026-09-27 23:59:59.999999', 'safe_metadata' => '{"private":"must-not-be-projected","state":"archived","revision":3,"job_id":"not-an-id"}']);
}
$auditFilters = ['correlation_id' => $auditCorrelation, 'actor_id' => 731, 'form_id' => $id, 'event_type' => 'form.save', 'from' => '2026-09-27', 'to' => '2026-09-27'];
$auditFirst = $auditViewer->page(1, $auditFilters); $auditSecond = $auditViewer->page(1, $auditFilters, $auditFirst['next_before']);
if (count($auditFirst['rows']) !== 100 || count($auditSecond['rows']) !== 2 || $auditSecond['next_before'] !== null || array_intersect(array_column($auditFirst['rows'], 'id'), array_column($auditSecond['rows'], 'id')) !== []) { throw new RuntimeException('Audit keyset pagination or inclusive date boundary failed.'); }
if (isset($auditFirst['rows'][0]['safe_metadata']) || str_contains(json_encode($auditFirst), 'must-not-be-projected')) { throw new RuntimeException('Audit viewer exposed free-form metadata.'); }
if ($auditFirst['rows'][0]['details'] !== ['revision' => 3, 'state' => 'archived']) { throw new RuntimeException('Audit viewer lost safe typed details or exposed an untyped reference.'); }
if ($auditViewer->page(1, array_replace($auditFilters, ['to' => '2026-09-26', 'from' => '2026-09-26']))['rows'] !== []) { throw new RuntimeException('Audit date filter ignored.'); }
foreach ([['event_type' => "x' OR 1=1"], ['correlation_id' => 'invalid'], ['actor_id' => '731'], ['form_id' => 0], ['from' => '2026-02-30'], ['to' => "2026-09-27\0"], ['from' => '2026-09-28', 'to' => '2026-09-27'], ['unknown' => 'filter']] as $invalid) {
    try { $auditViewer->page(1, $invalid); throw new LogicException('Invalid audit filter accepted.'); } catch (InvalidArgumentException) {}
}
try { $auditViewer->page(0); throw new LogicException('Audit ACL bypassed.'); } catch (DomainException) {}
echo "Audit viewer: log ACL, bound filters, inclusive UTC dates, private metadata exclusion and keyset pagination passed.\n";
