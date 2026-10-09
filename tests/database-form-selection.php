<?php
declare(strict_types=1);

$bulkIds = [];
for ($i = 0; $i < 2; $i++) {
    $bulkId = $formAdministration->create('Selection fixture ' . $i, 'selection-' . bin2hex(random_bytes(6)), 731);
    $bulkDraft = $forms->draft($bulkId); $bulkField = Nicode\FormStudio\Domain\Uuid::create();
    $bulkDraft['elements'] = [['uuid' => $bulkField, 'type' => 'field']];
    $bulkDraft['fields'] = [['uuid' => $bulkField, 'type' => $i === 0 ? 'text' : 'missing-provider', 'name' => 'answer', 'config' => []]];
    $formAdministration->save($bulkId, 0, $bulkDraft, 731); $bulkIds[] = $bulkId;
}
$bulkSelection = array_map(static fn (int $id): array => ['id' => $id, 'revision' => 1], $bulkIds);
$auditBefore = (int) $connection->row('SELECT COUNT(*) AS total FROM ' . $connection->table('audit_log'))['total'];
try { $formAdministration->bulk(array_reverse($bulkSelection), 'publish', 731); throw new RuntimeException('Invalid second draft committed the first publication.'); } catch (Nicode\FormStudio\Compiler\CompilationException) {}
foreach ($bulkIds as $bulkId) {
    if ($forms->get($bulkId)['state'] !== 'draft' || (int) $forms->get($bulkId)['draft_revision'] !== 1 || $forms->history($bulkId) !== []) { throw new RuntimeException('Bulk publication rollback lost atomicity.'); }
}
if ((int) $connection->row('SELECT COUNT(*) AS total FROM ' . $connection->table('audit_log'))['total'] !== $auditBefore) { throw new RuntimeException('Bulk rollback retained success audit.'); }
$bulkDraft['fields'][0]['type'] = 'text';
$formAdministration->save($bulkIds[1], 1, $bulkDraft, 731);
try { $formAdministration->bulk($bulkSelection, 'archived', 731); throw new RuntimeException('Stale selection accepted.'); } catch (Nicode\FormStudio\Domain\ConcurrentEdit) {}
if ($forms->get($bulkIds[0])['state'] !== 'draft') { throw new RuntimeException('Stale selection partly changed state.'); }
$bulkSelection[1]['revision'] = 2;
$restrictedBulk = new Nicode\FormStudio\Application\FormAdministration($forms, $connection, static fn (int $actor, ?int $form, string $permission): bool => $actor === 731 && !($form === $bulkIds[1] && $permission === 'core.delete'), $adminAttach, $adminCaptcha);
try { $restrictedBulk->bulk($bulkSelection, 'trashed', 731); throw new RuntimeException('Per-form trash permission bypass.'); } catch (DomainException) {}
foreach ([[], [$bulkSelection[0], $bulkSelection[0]], [['id' => (string) $bulkIds[0], 'revision' => 1]], array_fill(0, 101, $bulkSelection[0])] as $invalidBulk) {
    try { $formAdministration->bulk($invalidBulk, 'publish', 731); throw new RuntimeException('Invalid selection accepted.'); } catch (InvalidArgumentException) {}
}
foreach (['publish', 'archived', 'trashed', 'unpublished'] as $bulkOperation) {
    $bulkRows = $formAdministration->bulk(array_reverse($bulkSelection), $bulkOperation, 731);
    foreach ($bulkRows as $i => $bulkRow) {
        if ($bulkRow['id'] !== $bulkIds[$i] || $bulkRow['revision'] !== $bulkSelection[$i]['revision'] + 1 || $bulkRow['state'] !== ($bulkOperation === 'publish' ? 'published' : $bulkOperation)) { throw new RuntimeException('Bulk result state or revision mismatch.'); }
        $bulkSelection[$i]['revision'] = $bulkRow['revision'];
    }
}
echo "Form selection: bounded identities, per-form ACL, stale revisions, compiler rollback including versions/audit and atomic state transitions passed.\n";
