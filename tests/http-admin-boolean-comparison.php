<?php
declare(strict_types=1);
$booleanForm = $api('create', ['name' => 'Boolean confirmation acceptance', 'alias' => 'boolean-comparison-' . bin2hex(random_bytes(6))]);
$booleanDraft = $api('record', query: ['id' => $booleanForm['id']])['draft'];
$booleanLeft = 'dc95c782-5caa-4814-96db-266af62b5170';
$booleanRight = '50b9fd49-a199-408a-8e90-5fca7d5463f1';
$booleanFirstStep = '0205fb30-27b7-40ec-931c-e19fdf630d01';
$booleanSecondStep = '0205fb30-27b7-40ec-931c-e19fdf630d02';
$booleanDraft['elements'] = [
    ['uuid' => $booleanFirstStep, 'type' => 'step', 'config' => ['title' => 'Choose']],
    ['uuid' => $booleanLeft, 'type' => 'field', 'parent_uuid' => $booleanFirstStep],
    ['uuid' => $booleanSecondStep, 'type' => 'step', 'config' => ['title' => 'Confirm']],
    ['uuid' => $booleanRight, 'type' => 'field', 'parent_uuid' => $booleanSecondStep],
];
$booleanDraft['fields'] = [
    ['uuid' => $booleanLeft, 'name' => 'first_choice', 'type' => 'checkbox', 'config' => ['label' => 'First choice']],
    ['uuid' => $booleanRight, 'name' => 'confirmed_choice', 'type' => 'toggle', 'config' => ['label' => 'Confirmed choice']],
];
$booleanDraft['validators'] = [['type' => 'confirmation', 'config' => ['fields' => [$booleanLeft, $booleanRight]]]];
$booleanRevision = $api('save', ['id' => $booleanForm['id'], 'revision' => 0, 'draft' => $booleanDraft])['revision'];
$api('publish', ['id' => $booleanForm['id'], 'revision' => $booleanRevision]);
$booleanUrl = 'http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id=' . $booleanForm['id'];
$booleanPage = $visitorRequest($booleanUrl); $booleanXpath = $dom($booleanPage['body']);
$booleanNode = $booleanXpath->query('//form[@data-nfs-form]')->item(0);
$assert($booleanPage['status'] === 200 && $booleanNode instanceof DOMElement, 'Boolean comparison form did not render.');
$booleanPost = ['format' => 'json'];
foreach ($booleanXpath->query('.//input[@type="hidden"]', $booleanNode) as $input) { $booleanPost[$input->getAttribute('name')] = $input->getAttribute('value'); }
$booleanDestination = 'http://127.0.0.1:13371' . $booleanNode->getAttribute('action');
foreach ([['1', '0'], ['0', '1']] as [$left, $right]) {
    $booleanPost['nfs'] = [$booleanLeft => $left, $booleanRight => $right];
    $booleanRejected = $visitorRequest($booleanDestination, $booleanPost);
    $booleanResult = json_decode($booleanRejected['body'], true, 512, JSON_THROW_ON_ERROR);
    $assert($booleanRejected['status'] === 422 && isset($booleanResult['errors'][$booleanLeft], $booleanResult['errors'][$booleanRight]), 'False bypassed boolean confirmation.');
}
$booleanCount = $prefillDb->prepare('SELECT COUNT(*) FROM j6_nicode_form_studio_submissions WHERE form_id = ?');
$booleanCount->execute([$booleanForm['id']]); $assert((int) $booleanCount->fetchColumn() === 0, 'Rejected boolean comparison persisted a response.');
$booleanPost['nfs'] = [$booleanLeft => '0', $booleanRight => '0'];
$booleanAccepted = $visitorRequest($booleanDestination, $booleanPost);
$booleanResult = json_decode($booleanAccepted['body'], true, 512, JSON_THROW_ON_ERROR);
$assert($booleanAccepted['status'] === 200 && $booleanResult['category'] === 'success', 'Equal false values could not be submitted after correction.');
$prefillStatement->execute([$booleanForm['id'], $booleanResult['reference']]);
$booleanPayload = json_decode($prefillStatement->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
$assert($booleanPayload['values'][$booleanLeft] === false && $booleanPayload['values'][$booleanRight] === false, 'Canonical boolean comparison lost false values.');
file_put_contents($root . '/build/native-boolean-comparison-fixture.json', json_encode(['form_id' => $booleanForm['id'], 'left' => $booleanLeft, 'right' => $booleanRight], JSON_THROW_ON_ERROR));
echo "Native boolean confirmation: both mismatches reject without persistence; corrected false/false values persist as booleans.\n";
