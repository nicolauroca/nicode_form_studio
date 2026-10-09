<?php
declare(strict_types=1);
$duplicatePermissions = []; $duplicateFailure = false;
$duplicator = new Nicode\FormStudio\Application\FormDuplicator($connection, $forms, $formAdministration, new Nicode\FormStudio\Domain\DefinitionRemapper(), static function (int $source, int $target) use (&$duplicatePermissions, &$duplicateFailure): void {
    if ($duplicateFailure) { throw new RuntimeException('Synthetic permissions copy failure.'); }
    $duplicatePermissions[] = [$source, $target];
});
$duplicateSource = $forms->get($adminForm); $duplicateSourceDraft = $forms->draft($adminForm); $duplicateRevision = (int) $duplicateSource['draft_revision'];
$adminDenied = ['core.create'];
try { $duplicator->duplicate($adminForm, $duplicateRevision, 731, 'Denied copy', 'denied-copy'); throw new LogicException('Duplicate bypassed creation permission.'); } catch (DomainException) {}
$adminDenied = [];
try { $duplicator->duplicate($adminForm, $duplicateRevision - 1, 731, 'Stale copy', 'stale-copy'); throw new LogicException('Duplicate accepted a stale source.'); } catch (Nicode\FormStudio\Domain\ConcurrentEdit) {}
$copyResult = $duplicator->duplicate($adminForm, $duplicateRevision, 731, 'Copied draft', 'copy-' . bin2hex(random_bytes(6)));
$copied = $forms->get($copyResult['id']); $copiedDraft = $forms->draft($copyResult['id']);
if ($copied['state'] !== 'draft' || $copied['published_version_id'] !== null || $copied['uuid'] === $duplicateSource['uuid'] || $copiedDraft['fields'][0]['uuid'] === $duplicateSourceDraft['fields'][0]['uuid'] || $copied['language'] !== $duplicateSource['language'] || (int) $copied['access'] !== (int) $duplicateSource['access'] || $copied['publish_up'] !== $duplicateSource['publish_up']) { throw new RuntimeException('Duplicate identity, draft state or publication policy failed.'); }
if ($duplicatePermissions !== [[$adminForm, $copyResult['id']]] || $forms->draft($adminForm) !== $duplicateSourceDraft || (int) $forms->get($adminForm)['draft_revision'] !== $duplicateRevision) { throw new RuntimeException('Duplicate permissions callback failed or source was changed.'); }
foreach (['form_versions', 'submissions'] as $table) {
    if ((int) $connection->row('SELECT COUNT(*) AS total FROM ' . $connection->table($table) . ' WHERE form_id = :id', [':id' => $copyResult['id']])['total'] !== 0) { throw new RuntimeException('Duplicate copied runtime history.'); }
}
$duplicateFailure = true; $failedAlias = 'failed-copy-' . bin2hex(random_bytes(6));
try { $duplicator->duplicate($adminForm, $duplicateRevision, 731, 'Rolled back copy', $failedAlias); throw new LogicException('Duplicate ignored permission-copy failure.'); }
catch (RuntimeException $error) { if ($error->getMessage() !== 'Synthetic permissions copy failure.') { throw $error; } }
if ($connection->row('SELECT id FROM ' . $connection->table('forms') . ' WHERE alias = :alias', [':alias' => $failedAlias]) !== null) { throw new RuntimeException('Failed duplicate left partial state.'); }
echo "Form duplication: distinct graph identity, draft-only state, source isolation, preserved visibility, ACL gates and atomic rollback verified.\n";

$branchForm = $copyResult['id']; $branchRecord = $forms->get($branchForm); $branchDraft = $forms->draft($branchForm);
$branchSource = $branchDraft['fields'][0]['uuid']; $branchRevision = (int)$branchRecord['draft_revision'];
$adminDenied = ['core.edit'];
try { $duplicator->duplicateElement($branchForm,$branchRevision,$branchSource,731); throw new LogicException('Branch copy bypassed edit ACL.'); } catch (DomainException) {}
$adminDenied = [];
try { $duplicator->duplicateElement($branchForm,$branchRevision-1,$branchSource,731); throw new LogicException('Branch copy accepted stale revision.'); } catch (Nicode\FormStudio\Domain\ConcurrentEdit) {}
if ($forms->draft($branchForm) !== $branchDraft || $forms->get($branchForm) !== $branchRecord) { throw new RuntimeException('Rejected branch copy mutated the form.'); }
$branchResult = $duplicator->duplicateElement($branchForm,$branchRevision,$branchSource,731);
$branchSaved = $forms->draft($branchForm);
if ($branchSaved !== $branchResult['draft'] || count($branchSaved['fields']) !== count($branchDraft['fields'])+1 || $branchResult['selected'] === $branchSource || (int)$forms->get($branchForm)['draft_revision'] !== $branchRevision+1 || $forms->get($branchForm)['published_version_id'] !== null) { throw new RuntimeException('Branch copy persistence or identity failed.'); }
try { $duplicator->duplicateElement($branchForm,$branchRevision,$branchSource,731); throw new LogicException('Repeated branch copy accepted stale revision.'); } catch (Nicode\FormStudio\Domain\ConcurrentEdit) {}
echo "Branch duplication: edit ACL, revision fencing, new field identity, exact stored draft and no activation verified.\n";
