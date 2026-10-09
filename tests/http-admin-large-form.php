<?php
declare(strict_types=1);
$largeForm = $api('create', ['name' => '1500 field acceptance', 'alias' => 'large-form-' . bin2hex(random_bytes(6))]);
$largeDraft = $api('record', query: ['id' => $largeForm['id']])['draft'];
$largeDraft['elements'] = $largeDraft['fields'] = []; $largeValues = [];
for ($i = 0; $i < 1500; $i++) {
    $uuid = '7f897f73-cb45-4110-8000-' . str_pad((string) $i, 12, '0', STR_PAD_LEFT);
    $value = ' value ' . $i . ' 😀 '; $largeValues[$uuid] = $value;
    $largeDraft['elements'][] = ['uuid' => $uuid, 'type' => 'field', 'parent_uuid' => null];
    $largeDraft['fields'][] = ['uuid' => $uuid, 'name' => 'answer_' . $i, 'type' => 'text', 'config' => ['label' => 'Answer ' . $i, 'required' => true, 'trim' => false, 'default' => $value]];
}
$largeRevision = $api('save', ['id' => $largeForm['id'], 'revision' => 0, 'draft' => $largeDraft])['revision'];
$api('publish', ['id' => $largeForm['id'], 'revision' => $largeRevision]);
$largePage = $visitorRequest('http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id=' . $largeForm['id']);
$largeXpath = $dom($largePage['body']); $largeNode = $largeXpath->query('//form[@data-nfs-form]')->item(0);
$assert($largePage['status'] === 200 && $largeNode instanceof DOMElement && $largeXpath->query('.//*[@data-nfs-input]', $largeNode)->length === 1500, 'Large form lost rendered fields.');
$largePost = ['format' => 'json', 'nfs_values' => json_encode($largeValues + ['unknown' => 'ignored'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
foreach ($largeXpath->query('.//input[@type="hidden"]', $largeNode) as $input) { $largePost[$input->getAttribute('name')] = $input->getAttribute('value'); }
$largeDestination = 'http://127.0.0.1:13371' . $largeNode->getAttribute('action');
foreach ([['nfs_values' => '[]'], ['nfs_values' => '{'], ['nfs' => [array_key_first($largeValues) => 'ambiguous']]] as $invalid) {
    $badResponse = $visitorRequest($largeDestination, array_replace($largePost, $invalid));
    $assert($badResponse['status'] === 422, 'Malformed or mixed packed values were accepted.');
}
$largeResponse = $visitorRequest($largeDestination, $largePost); $largeResult = json_decode($largeResponse['body'], true, flags: JSON_THROW_ON_ERROR);
$assert($largeResponse['status'] === 200 && $largeResult['category'] === 'success', 'Packed large form submission failed.');
$prefillStatement->execute([$largeForm['id'], $largeResult['reference']]); $largePayload = json_decode($prefillStatement->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
$assert($largePayload['values'] === $largeValues, 'Packed large form lost or rewrote canonical values.');
file_put_contents($root . '/build/native-large-form-fixture.json', json_encode(['form_id' => $largeForm['id'], 'fields' => 1500, 'first' => array_key_first($largeValues), 'last' => array_key_last($largeValues)], JSON_THROW_ON_ERROR));
echo "Native large form: 1500 fields saved/published/rendered, malformed/mixed JSON rejected and all canonical Unicode values preserved.\n";
