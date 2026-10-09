<?php
declare(strict_types=1);

$selectionIds = [];
for ($i = 0; $i < 2; $i++) {
    $selectionCreated = $api('create', ['name' => 'HTTP selection ' . $i, 'alias' => 'http-selection-' . bin2hex(random_bytes(6))]);
    $selectionIds[] = $selectionCreated['id'];
}
$selectionPayload = ['operation' => 'archived', 'selection' => array_map(static fn (int $id): array => ['id' => $id, 'revision' => 0], $selectionIds)];
$api('bulk', expected: 405);
$api('bulk', $selectionPayload, expected: 403, csrf: false);
$api('bulk', ['operation' => 'delete', 'selection' => $selectionPayload['selection']], expected: 422);
$api('bulk', ['operation' => 'archived', 'selection' => [$selectionPayload['selection'][0], $selectionPayload['selection'][0]]], expected: 422);
$selectionResult = $api('bulk', $selectionPayload);
$assert(count($selectionResult['rows']) === 2 && array_column($selectionResult['rows'], 'state') === ['archived', 'archived'] && array_column($selectionResult['rows'], 'revision') === [1, 1], 'HTTP selection did not update both forms.');
$selectionPayload['selection'][0]['revision'] = 1;
$api('bulk', $selectionPayload, expected: 409);
foreach ($selectionIds as $selectionId) { $assert((int) $api('record', query: ['id' => $selectionId])['form']['draft_revision'] === 1, 'Stale HTTP selection partly changed state.'); }
$selectionPage = $request($base . '?option=com_nicode_form_studio&view=forms&search=HTTP%20selection');
$selectionDom = $dom($selectionPage['body']);
$assert($selectionPage['status'] === 200 && $selectionDom->query('//*[@data-nfs-form-selection]')->length === 1 && $selectionDom->query('//*[@data-nfs-select-form]')->length >= 2, 'Native list selection controls missing.');
file_put_contents($root . '/build/native-selection-fixture.json', json_encode(['ids' => $selectionIds], JSON_THROW_ON_ERROR));
echo "Native form selection HTTP: POST/CSRF, identity validation, atomic revision conflict and visible-page controls passed.\n";
