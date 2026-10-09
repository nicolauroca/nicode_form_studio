<?php
declare(strict_types=1);
$derivedForm = $api('create', ['name' => 'Derived values acceptance', 'alias' => 'derived-' . bin2hex(random_bytes(6))]);
$derivedDraft = $api('record', query: ['id' => $derivedForm['id']])['draft'];
$derivedDraft['fields'] = $derivedDraft['elements'] = []; $derivedIds = [];
foreach (['source' => 'text', 'hidden' => 'hidden', 'locked' => 'hidden', 'system' => 'system', 'calculated' => 'calculated'] as $name => $type) {
    $uuid = 'fbf38084-e791-4ca8-83e1-b4ff04daecb' . count($derivedIds); $derivedIds[$name] = $uuid;
    $derivedDraft['elements'][] = ['uuid' => $uuid, 'type' => 'field', 'parent_uuid' => null];
    $field = ['uuid' => $uuid, 'name' => $name, 'type' => $type, 'config' => ['label' => $name, 'default' => 'initial', 'required' => true, 'max_length' => 32]];
    if ($name === 'locked') { $field['config']['readonly'] = true; $field['prefill'] = ['type' => 'constant', 'value' => 'locked-server']; }
    if ($name === 'system') { $field['prefill'] = ['type' => 'constant', 'value' => 'system-server']; }
    if ($name === 'calculated') { $field['prefill'] = ['type' => 'field', 'field' => $derivedIds['source']]; }
    $derivedDraft['fields'][] = $field;
}
$derivedRevision = $api('save', ['id' => $derivedForm['id'], 'revision' => 0, 'draft' => $derivedDraft])['revision'];
$api('publish', ['id' => $derivedForm['id'], 'revision' => $derivedRevision]);
$derivedPage = $visitorRequest('http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id=' . $derivedForm['id']);
$derivedXpath = $dom($derivedPage['body']); $derivedNode = $derivedXpath->query('//form[@data-nfs-form]')->item(0);
$assert($derivedPage['status'] === 200 && $derivedNode instanceof DOMElement, 'Derived fixture failed to render.');
foreach (['hidden' => 'initial', 'locked' => 'locked-server', 'system' => 'system-server'] as $name => $value) {
    $input = $derivedXpath->query('.//input[@data-nfs-input="' . $derivedIds[$name] . '"]', $derivedNode)->item(0);
    $assert($input instanceof DOMElement && $input->getAttribute('type') === 'hidden' && $input->getAttribute('value') === $value, 'Hidden/system prefill rendering failed.');
}
$assert($derivedXpath->query('.//output[@data-nfs-output="' . $derivedIds['calculated'] . '"]', $derivedNode)->item(0)?->textContent === 'initial', 'Calculated initial output did not resolve its source.');
$derivedPost = ['format' => 'json', 'nfs' => array_fill_keys(array_values($derivedIds), 'forged')];
foreach ($derivedXpath->query('.//input[@type="hidden" and not(@data-nfs-input)]', $derivedNode) as $input) { $derivedPost[$input->getAttribute('name')] = $input->getAttribute('value'); }
$derivedDestination = 'http://127.0.0.1:13371' . $derivedNode->getAttribute('action');
$invalidDerived = $derivedPost; $invalidDerived['nfs'][$derivedIds['hidden']] = str_repeat('a', 33);
$invalidDerivedResponse = $visitorRequest($derivedDestination, $invalidDerived); $invalidDerivedResult = json_decode($invalidDerivedResponse['body'], true, flags: JSON_THROW_ON_ERROR);
$assert($invalidDerivedResponse['status'] === 422 && isset($invalidDerivedResult['errors'][$derivedIds['hidden']]), 'Hidden input bypassed normal validation.');
$derivedPost['nfs'][$derivedIds['source']] = '  accepted source  '; $derivedPost['nfs'][$derivedIds['hidden']] = 'edited hidden';
$derivedPost['nfs'][$derivedIds['locked']] = ['forged']; $derivedPost['nfs'][$derivedIds['system']] = ['forged']; $derivedPost['nfs'][$derivedIds['calculated']] = ['forged'];
$derivedResponse = $visitorRequest($derivedDestination, $derivedPost); $derivedResult = json_decode($derivedResponse['body'], true, flags: JSON_THROW_ON_ERROR);
$assert($derivedResponse['status'] === 200 && $derivedResult['category'] === 'success', 'Authoritative values depended on forged POST shape.');
$prefillStatement->execute([$derivedForm['id'], $derivedResult['reference']]); $derivedPayload = json_decode($prefillStatement->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
foreach (['source' => 'accepted source', 'hidden' => 'edited hidden', 'locked' => 'locked-server', 'system' => 'system-server', 'calculated' => 'accepted source'] as $name => $value) { $assert($derivedPayload['values'][$derivedIds[$name]] === $value, 'Derived field authority or normalization changed: ' . $name); }
file_put_contents($root . '/build/native-derived-fixture.json', json_encode(['form_id' => $derivedForm['id'], 'fields' => $derivedIds], JSON_THROW_ON_ERROR));
echo "Native derived fields: hidden input validation, readonly/system POST tampering rejection and recalculated canonical source copy passed.\n";
