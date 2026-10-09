<?php
declare(strict_types=1);

// Included by the isolated reindex fixture; exercises real publication, policy, job and search repositories.
$bfForm = $forms->create('Historical backfill', 'historical-backfill-' . bin2hex(random_bytes(6)), 1); $fixtureForms[] = $bfForm;
$bfDraft = $forms->draft($bfForm); $bfFields = [];
foreach (['answer', 'consented_secret', 'unconsented_secret'] as $name) {
    $uuid = Nicode\FormStudio\Domain\Uuid::create(); $bfFields[$name] = $uuid;
    $bfDraft['elements'][] = ['uuid' => $uuid, 'type' => 'field'];
    $bfDraft['fields'][] = ['uuid' => $uuid, 'type' => 'text', 'name' => $name, 'index' => false, 'sensitive' => $name !== 'answer', 'allow_sensitive_index' => $name === 'consented_secret', 'config' => ['max_length' => 255]];
}
$bfPublish = static function () use ($forms, $bfForm, &$bfDraft): int {
    return $forms->publish($bfForm, $forms->saveDraft($bfForm, (int) $forms->get($bfForm)['draft_revision'], $bfDraft, 1), 1);
};
$bfVersion = $bfPublish(); $bfOriginal = $forms->version($bfForm, $bfVersion); $bfRows = [];
foreach ([1, 2] as $i) {
    $saved = $responses->persist($bfForm, $bfVersion, $bfOriginal, array_fill_keys(array_values($bfFields), 'historical-' . $i), hash('sha256', random_bytes(32)));
    $bfRows[$saved->id] = $responses->get($bfForm, $saved->id)['canonical_payload'];
}
foreach ($bfDraft['fields'] as &$field) { $field['index'] = true; $field['sensitive'] = false; } unset($field);
$bfCurrentVersion = $bfPublish(); $bfCurrent = $forms->version($bfForm, $bfCurrentVersion);
$bfLatestJob = static fn (): int => (int) $db->row('SELECT MAX(id) AS id FROM ' . $db->table('jobs') . ' WHERE form_id = :form', [':form' => $bfForm])['id'];
$bfPending = static fn (): int => (int) $db->row('SELECT COUNT(*) AS total FROM ' . $db->table('submissions') . ' WHERE form_id = :form AND index_pending = 1', [':form' => $bfForm])['total'];
$assert($bfPending() === 2 && $jobs->get($bfLatestJob())['state'] === 'pending', 'Publication failed to atomically mark and enqueue historical indexing.');
$bfQuery = static fn (string $name, string $operator = 'equals'): Nicode\FormStudio\Search\SearchRequest => new Nicode\FormStudio\Search\SearchRequest(['form_id' => $bfForm], [['field' => $bfFields[$name], 'operator' => $operator, 'value' => 'historical-1']]);
$bfScope = new Nicode\FormStudio\Search\SearchScope([$bfForm => false]); $bfSensitive = new Nicode\FormStudio\Search\SearchScope([$bfForm => true]);
$assert($search->search($bfQuery('answer', 'not_equals'), $bfScope, $bfCurrent)->rows === [], 'Pending projection produced a false negative match.');
$run($bfLatestJob());
$assert($bfPending() === 0 && count($search->search($bfQuery('answer'), $bfScope, $bfCurrent)->rows) === 1, 'Backfill failed to make an old non-indexed value searchable.');
$assert($search->search($bfQuery('consented_secret'), $bfScope, $bfCurrent)->rows === [] && count($search->search($bfQuery('consented_secret'), $bfSensitive, $bfCurrent)->rows) === 1, 'Backfill changed historical sensitivity.');
$assert($search->search($bfQuery('unconsented_secret'), $bfSensitive, $bfCurrent)->rows === [], 'Backfill widened historical sensitive-index consent.');
// Finish an accepted old-version submission after the first backfill has already completed.
$bfLate = $responses->persist($bfForm, $bfVersion, $bfOriginal, [$bfFields['answer'] => 'historical-1'], hash('sha256', random_bytes(32)));
$bfRows[$bfLate->id] = $responses->get($bfForm, $bfLate->id)['canonical_payload'];
$assert($bfPending() === 1, 'Late historical submission escaped pending tracking.'); $run($bfLatestJob());
$assert(count($search->search($bfQuery('answer'), $bfScope, $bfCurrent)->rows) === 2, 'Late historical submission escaped backfill.');
// Publish a conflicting policy between two bounded chunks of a running job.
$bfChanged = false;
$run($admin->enqueue(1, $bfForm, 'reindex', []), [], static function ($progress) use (&$bfChanged, &$bfDraft, $bfPublish): void {
    if (!$bfChanged && $progress->processed > 0) {
        $bfChanged = true; foreach ($bfDraft['fields'] as &$field) { $field['index'] = false; } unset($field); $bfPublish();
    }
});
$assert($bfChanged && $indexed($bfForm) === [] && $bfPending() === 0, 'Resumed job wrote or cleared pending under an obsolete policy.');
$run($bfLatestJob()); // Automatically queued job is safe to replay after another job completed the work.
foreach ($bfRows as $id => $payload) { $assert($responses->get($bfForm, $id)['canonical_payload'] === $payload, 'Backfill rewrote canonical data.'); }
$assert($forms->version($bfForm, $bfVersion)->json === $bfOriginal->json, 'Backfill rewrote an immutable snapshot.');
file_put_contents($root . '/build/index-backfill' . $suffix . '-results.json', json_encode(['passed' => true, 'database' => $driver->getVersion(), 'form' => $bfForm, 'checks' => ['publication pending and automatic job', 'old non-indexed value searchable', 'no pending negative matches', 'historical sensitivity and consent', 'late old-version submission', 'publication between chunks restarts policy', 'idempotent replay', 'canonical payload and snapshot unchanged'], 'timestamp' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Historical index backfill passed: publication, pending filters, privacy, late submissions, policy change between chunks, replay and immutable canonical data.\n";
