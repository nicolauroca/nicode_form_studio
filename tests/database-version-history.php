<?php
declare(strict_types=1);

$historyForm = $forms->create('Version history', 'versions-' . bin2hex(random_bytes(6)), 731);
$historyDraft = $forms->draft($historyForm); $historyField = Nicode\FormStudio\Domain\Uuid::create();
$historyDraft['elements'] = [['uuid' => $historyField, 'type' => 'field']];
$historyDraft['fields'] = [['uuid' => $historyField, 'type' => 'text', 'name' => 'historical', 'config' => ['label' => 'Original']]];
$historyRevision = $forms->saveDraft($historyForm, 0, $historyDraft, 731);
$historyVersions = [];
for ($number = 1; $number <= 52; $number++) {
    $historyVersions[] = $forms->publish($historyForm, $historyRevision, 731, 'Revision ' . $number);
    $historyRevision = (int) $forms->get($historyForm)['draft_revision'];
}
$historical = $forms->version($historyForm, $historyVersions[0]);
$historyResponse = $submissions->persist($historyForm, $historyVersions[0], $historical, [$historyField => 'old answer'], hash('sha256', random_bytes(32)));
$firstPage = $forms->history($historyForm, includeCounts: true);
$secondPage = $forms->history($historyForm, (int) $firstPage[49]['revision'], includeCounts: true);
if (count($firstPage) !== 50 || count($secondPage) !== 2 || count(array_unique(array_column([...$firstPage, ...$secondPage], 'id'))) !== 52 || $firstPage[0]['version_state'] !== 'active' || $secondPage[1]['version_state'] !== 'historical' || $secondPage[1]['submission_count'] !== 1 || $firstPage[0]['submission_count'] !== 0) { throw new RuntimeException('Version history pagination, state or counts failed.'); }
$historyRead = false;
$historyAdmin = new Nicode\FormStudio\Application\FormAdministration($forms, $connection, static function (int $actor, ?int $form, string $permission) use (&$historyRead): bool { return $actor === 731 && ($permission !== 'formstudio.submissions.view' || $historyRead); }, static fn () => null, $adminCaptcha);
if (array_key_exists('submission_count', $historyAdmin->history($historyForm, 731)[0])) { throw new RuntimeException('Author without response permission saw response counts.'); }
$historyRead = true;
if ($historyAdmin->history($historyForm, 731)[0]['submission_count'] !== 0) { throw new RuntimeException('Authorized version count missing.'); }
$historyRevision = $historyAdmin->restore($historyForm, $historyVersions[0], $historyRevision, 731);
if ((int) $forms->get($historyForm)['published_version_id'] !== $historyVersions[51] || $forms->version($historyForm, $historyVersions[0])->hash !== $historical->hash || (int) $submissions->get($historyForm, $historyResponse->id)['form_version_id'] !== $historyVersions[0]) { throw new RuntimeException('Restore changed publication, snapshot or historical response identity.'); }
$historyAdmin->deactivate($historyForm, $historyRevision, 731);
if ($historyAdmin->history($historyForm, 731)[0]['version_state'] !== 'historical') { throw new RuntimeException('Deactivated version still displayed active.'); }
echo "Version history: 52 immutable snapshots, bounded pages, active state, ACL-scoped counts and restore preserving response identity passed.\n";
