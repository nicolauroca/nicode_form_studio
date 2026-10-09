<?php
declare(strict_types=1);
// Uses the authenticated test administrator and isolated visitor session.
$sliderForm = $api('create', ['name' => 'Slider bounds acceptance', 'alias' => 'slider-' . bin2hex(random_bytes(6))]);
$sliderDraft = $api('record', query: ['id' => $sliderForm['id']])['draft'];
$sliderUuid = 'ed714642-9422-4147-941d-a3eaa493d005';
$sliderDraft['elements'] = [['uuid' => $sliderUuid, 'type' => 'field', 'parent_uuid' => null]];
$sliderDraft['fields'] = [['uuid' => $sliderUuid, 'type' => 'range', 'name' => 'rating', 'config' => ['label' => 'Rating', 'required' => true, 'min' => '101']]];
$sliderRevision = $api('save', ['id' => $sliderForm['id'], 'revision' => 0, 'draft' => $sliderDraft])['revision'];
$api('publish', ['id' => $sliderForm['id'], 'revision' => $sliderRevision], expected: 422);
unset($sliderDraft['fields'][0]['config']['min']);
$numericIds = []; $numericValues = [];
foreach (['integer', 'decimal', 'number', 'currency'] as $index => $type) {
    $uuid = '71eeb6d5-c7aa-4493-8d7a-544529f31ce' . $index; $numericIds[$type] = $uuid;
    $sliderDraft['elements'][] = ['uuid' => $uuid, 'type' => 'field', 'parent_uuid' => null];
    $config = ['label' => $type, 'required' => true];
    $config += $type === 'integer' ? ['min' => '-10', 'max' => '10', 'step' => '2'] : ['min' => '-100', 'max' => '100', 'step' => '0.05', 'scale' => 2, 'precision' => 5];
    $sliderDraft['fields'][] = ['uuid' => $uuid, 'type' => $type, 'name' => $type, 'index' => true, 'config' => $config];
    $numericValues[$uuid] = $type === 'integer' ? '8' : '12.50';
}
$sliderRevision = $api('save', ['id' => $sliderForm['id'], 'revision' => $sliderRevision, 'draft' => $sliderDraft])['revision'];
$api('publish', ['id' => $sliderForm['id'], 'revision' => $sliderRevision]);
$sliderPage = $visitorRequest('http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id=' . $sliderForm['id']);
$sliderXpath = $dom($sliderPage['body']); $sliderNode = $sliderXpath->query('//form[@data-nfs-form]')->item(0);
$assert($sliderPage['status'] === 200 && $sliderNode instanceof DOMElement, 'Slider form failed to render.');
$sliderInput = $sliderXpath->query('.//input[@type="range"]', $sliderNode)->item(0);
foreach (['min' => '0', 'max' => '100', 'step' => '1'] as $key => $value) { $assert($sliderInput instanceof DOMElement && $sliderInput->getAttribute($key) === $value, 'Slider default attribute mismatch.'); }
$sliderPost = ['format' => 'json', 'nfs' => [$sliderUuid => '50'] + $numericValues];
foreach ($numericIds as $type => $uuid) {
    $input = $sliderXpath->query('.//input[@data-nfs-input="' . $uuid . '"]', $sliderNode)->item(0);
    $assert($input instanceof DOMElement && $input->getAttribute('type') === 'number', 'Numeric native input is missing.');
}
foreach ($sliderXpath->query('.//input[@type="hidden"]', $sliderNode) as $input) { $sliderPost[$input->getAttribute('name')] = $input->getAttribute('value'); }
$sliderDestination = 'http://127.0.0.1:13371' . $sliderNode->getAttribute('action');
foreach (['-1', '101', '0.5'] as $invalid) {
    $invalidPost = $sliderPost; $invalidPost['nfs'][$sliderUuid] = $invalid;
    $response = $visitorRequest($sliderDestination, $invalidPost); $result = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    $assert($response['status'] === 422 && isset($result['errors'][$sliderUuid]), 'Direct POST bypassed slider defaults.');
}
foreach ($numericIds as $type => $uuid) {
    foreach ($type === 'integer' ? ['0.5', '9', '12', '9223372036854775808'] : ['100.05', '12.51', '1.234', '1e2'] as $invalid) {
        $invalidPost = $sliderPost; $invalidPost['nfs'][$uuid] = $invalid;
        $response = $visitorRequest($sliderDestination, $invalidPost); $result = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        $assert($response['status'] === 422 && isset($result['errors'][$uuid]), 'Direct POST bypassed numeric normalization or limits.');
    }
}
$response = $visitorRequest($sliderDestination, $sliderPost); $result = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
$assert($response['status'] === 200 && $result['category'] === 'success', 'Valid slider submission failed.');
$prefillStatement->execute([$sliderForm['id'], $result['reference']]); $sliderPayload = json_decode($prefillStatement->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
$assert($sliderPayload['values'][$sliderUuid] === '50', 'Slider canonical decimal changed.');
foreach ($numericIds as $type => $uuid) { $assert($sliderPayload['values'][$uuid] === ($type === 'integer' ? 8 : '12.5'), 'Numeric canonical storage lost its logical type or precision.'); }
file_put_contents($root . '/build/native-numeric-fixture.json', json_encode(['form_id' => $sliderForm['id'], 'fields' => ['range' => $sliderUuid] + $numericIds], JSON_THROW_ON_ERROR));
echo "Native numeric fields: five types, slider defaults, publication bounds, HTML constraints, direct POST normalization/limit rejection and canonical persistence passed.\n";
