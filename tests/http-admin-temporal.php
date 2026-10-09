<?php
declare(strict_types=1);
// Included inside the authenticated native validation fixture's visitor session.
$temporalForm = $api('create', ['name' => 'Temporal field acceptance', 'alias' => 'temporal-' . bin2hex(random_bytes(6))]);
$temporalDraft = $api('record', query: ['id' => $temporalForm['id']])['draft'];
$temporalDraft['fields'] = $temporalDraft['elements'] = [];
$temporalTypes = ['date', 'time', 'datetime-local', 'month', 'week']; $temporalValues = []; $temporalIds = [];
$temporalValid = ['2024-02-29', '12:00', '2026-09-27T12:00', '2026-09', '2026-W39'];
foreach ($temporalTypes as $index => $type) {
    $uuid = 'aa452210-c638-4e1e-8021-928ffef6092' . $index; $temporalIds[$type] = $uuid; $temporalValues[$uuid] = $temporalValid[$index];
    $config = ['label' => $type, 'required' => true];
    if ($type === 'time') { $config += ['min' => '12:00:00', 'max' => '13:00:00']; }
    if ($type === 'datetime-local') { $config += ['min' => '2026-09-27T12:00:00', 'max' => '2026-09-27T13:00:00']; }
    if ($type === 'week') { $config += ['min' => '2021-W53', 'max' => '2026-W40']; }
    $temporalDraft['elements'][] = ['uuid' => $uuid, 'type' => 'field', 'parent_uuid' => null];
    $temporalDraft['fields'][] = ['uuid' => $uuid, 'name' => str_replace('-', '_', $type), 'type' => $type, 'index' => true, 'config' => $config];
}
$temporalRevision = $api('save', ['id' => $temporalForm['id'], 'revision' => 0, 'draft' => $temporalDraft])['revision'];
$api('publish', ['id' => $temporalForm['id'], 'revision' => $temporalRevision], expected: 422);
$temporalDraft['fields'][4]['config']['min'] = '2026-W39';
$temporalRevision = $api('save', ['id' => $temporalForm['id'], 'revision' => $temporalRevision, 'draft' => $temporalDraft])['revision'];
$api('publish', ['id' => $temporalForm['id'], 'revision' => $temporalRevision]);
$temporalPage = $visitorRequest('http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id=' . $temporalForm['id']);
$temporalXpath = $dom($temporalPage['body']); $temporalNode = $temporalXpath->query('//form[@data-nfs-form]')->item(0);
$assert($temporalPage['status'] === 200 && $temporalNode instanceof DOMElement, 'Native temporal form failed to render.');
$temporalPost = ['format' => 'json', 'nfs' => $temporalValues];
foreach ($temporalXpath->query('.//input[@type="hidden"]', $temporalNode) as $input) { $temporalPost[$input->getAttribute('name')] = $input->getAttribute('value'); }
$temporalDestination = 'http://127.0.0.1:13371' . $temporalNode->getAttribute('action');
foreach ($temporalTypes as $type) {
    $input = $temporalXpath->query('.//input[@data-nfs-input="' . $temporalIds[$type] . '"]', $temporalNode)->item(0);
    $assert($input instanceof DOMElement && $input->getAttribute('type') === $type, 'Native temporal control type missing.');
}
foreach (['date' => ['2023-02-29', '0001-01-01'], 'time' => ['24:00', '11:59:59'], 'datetime-local' => ['2026-09-27T12:00Z', '2026-09-27T13:00:01'], 'month' => ['2026-13'], 'week' => ['2021-W53', '2026-W41']] as $type => $invalidValues) {
    foreach ($invalidValues as $value) {
        $invalidPost = $temporalPost; $invalidPost['nfs'][$temporalIds[$type]] = $value;
        $response = $visitorRequest($temporalDestination, $invalidPost); $result = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        $assert($response['status'] === 422 && isset($result['errors'][$temporalIds[$type]]), 'Direct POST bypassed temporal calendar, bound or index-range validation.');
    }
}
$response = $visitorRequest($temporalDestination, $temporalPost); $result = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
$assert($response['status'] === 200 && $result['category'] === 'success', 'Equivalent minute/second temporal bounds rejected valid submission.');
$prefillStatement->execute([$temporalForm['id'], $result['reference']]); $temporalPayload = json_decode($prefillStatement->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
foreach ($temporalValues as $uuid => $value) { $assert($temporalPayload['values'][$uuid] === $value, 'Temporal persistence changed the accepted local value.'); }
file_put_contents($root . '/build/native-temporal-fixture.json', json_encode(['form_id' => $temporalForm['id'], 'fields' => $temporalIds], JSON_THROW_ON_ERROR));
echo "Native temporal fields: publication diagnostics, five input types, calendar/bounds/index-range direct POST rejection, minute/second equivalence and canonical persistence passed.\n";
