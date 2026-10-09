<?php
declare(strict_types=1);
$selectionForm = $api('create', ['name' => 'Selection controls acceptance', 'alias' => 'selection-controls-' . bin2hex(random_bytes(6))]);
$selectionDraft = $api('record', query: ['id' => $selectionForm['id']])['draft'];
$selectionDraft['fields'] = $selectionDraft['elements'] = []; $selectionIds = []; $selectionValues = []; $selectionExpected = []; $selectionInvalid = [];
foreach (['select', 'radio', 'button-group', 'multiselect', 'checkbox-group', 'checkbox', 'toggle', 'yes-no'] as $index => $type) {
    $uuid = '219d15ce-01fa-469a-a14c-634d852947c' . $index; $selectionIds[$type] = $uuid;
    $multiple = in_array($type, ['multiselect', 'checkbox-group'], true); $boolean = in_array($type, ['checkbox', 'toggle', 'yes-no'], true);
    $config = ['label' => $type, 'required' => true]; if ($multiple) { $config += ['min_selections' => 2, 'max_selections' => 2]; }
    $field = ['uuid' => $uuid, 'type' => $type, 'name' => str_replace('-', '_', $type), 'index' => true, 'config' => $config];
    if (!$boolean) {
        $field['options'] = [];
        foreach ([' a ', 'b', 'c', 'disabled'] as $ordinal => $value) { $field['options'][] = ['uuid' => '5645019e-c984-4183-a82c-700210a6d1' . $index . $ordinal, 'value' => $value, 'label' => $value === ' a ' ? 'Literal spaced value' : $value, 'enabled' => $value !== 'disabled']; }
    }
    $selectionDraft['fields'][] = $field; $selectionDraft['elements'][] = ['uuid' => $uuid, 'type' => 'field', 'parent_uuid' => null];
    $selectionValues[$uuid] = $boolean ? '1' : ($multiple ? [' a ', 'b', ' a '] : ' a ');
    $selectionExpected[$uuid] = $boolean ? true : ($multiple ? [' a ', 'b'] : ' a ');
    $selectionInvalid[$uuid] = $boolean ? ['yes', '0'] : ($multiple ? [[' a '], [' a ', 'b', 'c'], ['a', 'b'], ['disabled', 'b']] : ['a', 'disabled', 'unknown']);
}
$selectionRevision = $api('save', ['id' => $selectionForm['id'], 'revision' => 0, 'draft' => $selectionDraft])['revision'];
$api('publish', ['id' => $selectionForm['id'], 'revision' => $selectionRevision]);
$selectionPage = $visitorRequest('http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id=' . $selectionForm['id']);
$selectionXpath = $dom($selectionPage['body']); $selectionNode = $selectionXpath->query('//form[@data-nfs-form]')->item(0);
$assert($selectionPage['status'] === 200 && $selectionNode instanceof DOMElement, 'Selection fixture failed to render.');
$selectionPost = ['format' => 'json', 'nfs' => $selectionValues];
foreach ($selectionXpath->query('.//input[@type="hidden"]', $selectionNode) as $input) { $selectionPost[$input->getAttribute('name')] = $input->getAttribute('value'); }
$selectionDestination = 'http://127.0.0.1:13371' . $selectionNode->getAttribute('action');
foreach ($selectionInvalid as $uuid => $invalids) {
    foreach ($invalids as $invalid) {
        $invalidPost = $selectionPost; $invalidPost['nfs'][$uuid] = $invalid;
        $response = $visitorRequest($selectionDestination, $invalidPost); $result = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        $assert($response['status'] === 422 && isset($result['errors'][$uuid]), 'Selection direct POST bypassed membership/count/boolean validation.');
    }
}
$traditionalPost = $selectionPost; unset($traditionalPost['format'], $traditionalPost['nfs']);
$traditionalResponse = $visitorRequest($selectionDestination, $traditionalPost);
$traditionalXpath = $dom($traditionalResponse['body']);
$errorLinks = $traditionalXpath->query('//a[@data-nfs-error-target]');
$assert($traditionalResponse['status'] === 422 && $errorLinks->length === 8, 'Traditional selection errors did not expose all field destinations.');
foreach ($errorLinks as $link) {
    $targets = $traditionalXpath->query('//*[@id="'.substr($link->getAttribute('href'), 1).'"]');
    $assert($targets->length === 1 && $targets->item(0)->getAttribute('tabindex') === '-1' && $targets->item(0)->getAttribute('data-nfs-element') === $link->getAttribute('data-nfs-error-target'), 'Traditional selection error has no unique focusable field target.');
}
$response = $visitorRequest($selectionDestination, $selectionPost); $result = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
$assert($response['status'] === 200 && $result['category'] === 'success', 'Valid selection controls failed submission.');
$prefillStatement->execute([$selectionForm['id'], $result['reference']]); $selectionPayload = json_decode($prefillStatement->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
foreach ($selectionExpected as $uuid => $value) { $assert($selectionPayload['values'][$uuid] === $value, 'Selection canonical identity or logical type changed.'); }
file_put_contents($root . '/build/native-selection-controls-fixture.json', json_encode(['form_id' => $selectionForm['id'], 'fields' => $selectionIds], JSON_THROW_ON_ERROR));
echo "Native selection controls: eight providers, literal identity, duplicate removal, required/count bounds, disabled/unknown values and strict booleans passed.\n";
