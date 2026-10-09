<?php
declare(strict_types=1);
$stepsForm = $api('create', ['name' => 'Conditional step identity acceptance', 'alias' => 'conditional-steps-' . bin2hex(random_bytes(6))]);
$stepsDraft = $api('record', query: ['id' => $stepsForm['id']])['draft'];
$stepIds = ['3ecc752f-b142-4495-9cb5-dd35b9db2601', '3ecc752f-b142-4495-9cb5-dd35b9db2602', '3ecc752f-b142-4495-9cb5-dd35b9db2603'];
$stepFields = ['58950d73-c4f1-4352-b9e9-561b13d15201', '58950d73-c4f1-4352-b9e9-561b13d15202', '58950d73-c4f1-4352-b9e9-561b13d15203'];
$stepsDraft['elements'] = [];
foreach ($stepIds as $i => $uuid) {
    $stepsDraft['elements'][] = ['uuid' => $uuid, 'type' => 'step'];
    $stepsDraft['elements'][] = ['uuid' => $stepFields[$i], 'type' => 'field', 'parent_uuid' => $uuid];
}
$stepsDraft['fields'] = [
    ['uuid' => $stepFields[0], 'name' => 'initial_answer', 'type' => 'text', 'config' => ['label' => 'Initial answer', 'required' => true]],
    ['uuid' => $stepFields[1], 'name' => 'hide_initial_step', 'type' => 'checkbox', 'config' => ['label' => 'Hide initial step']],
    ['uuid' => $stepFields[2], 'name' => 'final_answer', 'type' => 'text', 'config' => ['label' => 'Final answer']],
];
$stepsDraft['rules'] = [['uuid' => '72a94cd6-a49a-44f4-bcf0-d68f76b4656d', 'when' => ['field' => $stepFields[1], 'operator' => 'equals', 'value' => true], 'effects' => [['type' => 'hide', 'target' => $stepIds[0]]]]];
$stepsRevision = $api('save', ['id' => $stepsForm['id'], 'revision' => 0, 'draft' => $stepsDraft])['revision'];
$api('publish', ['id' => $stepsForm['id'], 'revision' => $stepsRevision]);
$stepsUrl = 'http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id=' . $stepsForm['id'];
$stepsPage = $visitorRequest($stepsUrl); $stepsXpath = $dom($stepsPage['body']);
$stepsNode = $stepsXpath->query('//form[@data-nfs-form]')->item(0);
$assert($stepsPage['status'] === 200 && $stepsNode instanceof DOMElement && $stepsXpath->query('.//*[@data-nfs-step]', $stepsNode)->length === 3, 'Three conditional steps did not render.');
$stepsPost = ['format' => 'json', 'nfs' => [$stepFields[0] => 'forged inactive answer', $stepFields[1] => '1', $stepFields[2] => 'Final authoritative answer']];
foreach ($stepsXpath->query('.//input[@type="hidden"]', $stepsNode) as $input) { $stepsPost[$input->getAttribute('name')] = $input->getAttribute('value'); }
$stepsResponse = $visitorRequest('http://127.0.0.1:13371' . $stepsNode->getAttribute('action'), $stepsPost);
$stepsResult = json_decode($stepsResponse['body'], true, 512, JSON_THROW_ON_ERROR);
$assert($stepsResponse['status'] === 200 && $stepsResult['category'] === 'success', 'Conditional step direct submission failed.');
$prefillStatement->execute([$stepsForm['id'], $stepsResult['reference']]);
$stepsPayload = json_decode($prefillStatement->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
$assert(!array_key_exists($stepFields[0], $stepsPayload['values']) && $stepsPayload['values'][$stepFields[1]] === true && $stepsPayload['values'][$stepFields[2]] === 'Final authoritative answer', 'Inactive step input persisted or active step values changed.');
file_put_contents($root . '/build/native-conditional-steps-fixture.json', json_encode(['form_id' => $stepsForm['id'], 'steps' => $stepIds, 'fields' => $stepFields], JSON_THROW_ON_ERROR));
echo "Native conditional steps: three-step publication/rendering and authoritative omission of forged inactive-step input passed.\n";

// Persistent controls outside the steps can hide the current step without
// deactivating their own condition input. Keep this separate from identity retention.
$fallbackForm = $api('create', ['name' => 'Step fallback and reset acceptance', 'alias' => 'step-fallback-' . bin2hex(random_bytes(6))]);
$fallbackDraft = $api('record', query: ['id' => $fallbackForm['id']])['draft'];
$fallbackDraft['elements'] = $stepsDraft['elements'];
foreach ($fallbackDraft['elements'] as &$stepElement) {
    if ($stepElement['type'] === 'step') { $stepElement['title'] = 'Answer stage'; $stepElement['description'] = 'Complete this stage <safely>.'; }
}
unset($stepElement);
$fallbackDraft['fields'] = $stepsDraft['fields'];
$fallbackDraft['fields'][1] = ['uuid' => $stepFields[1], 'name' => 'middle_answer', 'type' => 'text', 'config' => ['label' => 'Middle answer']];
$fallbackDraft['rules'] = [];
foreach (['initial', 'middle', 'final'] as $i => $name) {
    $control = 'bf7e4f3a-8f8b-4c87-8a15-985d5f42010' . $i;
    array_unshift($fallbackDraft['elements'], ['uuid' => $control, 'type' => 'field']);
    $fallbackDraft['fields'][] = ['uuid' => $control, 'name' => 'hide_' . $name, 'type' => 'checkbox', 'config' => ['label' => 'Hide ' . $name . ' step']];
    $fallbackDraft['rules'][] = ['uuid' => '124e4a6c-8e2d-4a23-96b7-93eea9cf201' . $i, 'when' => ['field' => $control, 'operator' => 'equals', 'value' => true], 'effects' => [['type' => 'hide', 'target' => $stepIds[$i]]]];
}
$fallbackDraft['post_submit'] = ['behavior' => 'reset', 'preserve' => [$stepFields[2]]];
$nestedDraft = $fallbackDraft;
foreach ($nestedDraft['elements'] as $index => &$nestedElement) {
    if ($nestedElement['uuid'] === $stepIds[1]) { $nestedElement['parent_uuid'] = $stepIds[0]; $nestedIndex = $index; }
}
unset($nestedElement);
$nestedRevision = $api('save', ['id' => $fallbackForm['id'], 'revision' => 0, 'draft' => $nestedDraft])['revision'];
$nestedResult = $api('publish', ['id' => $fallbackForm['id'], 'revision' => $nestedRevision], expected: 422);
$nestedDiagnostics = array_values(array_filter($nestedResult['diagnostics'], static fn (array $diagnostic): bool => $diagnostic['code'] === 'layout.step.nested'));
$assert(count($nestedDiagnostics) === 1 && $nestedDiagnostics[0]['path'] === '/elements/' . $nestedIndex . '/parent_uuid', 'Nested step publication did not identify the invalid parent.');
$fallbackRevision = $api('save', ['id' => $fallbackForm['id'], 'revision' => $nestedRevision, 'draft' => $fallbackDraft])['revision'];
$api('publish', ['id' => $fallbackForm['id'], 'revision' => $fallbackRevision]);
$fallbackPage = $visitorRequest('http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id=' . $fallbackForm['id']);
$fallbackXpath = $dom($fallbackPage['body']);
$assert($fallbackPage['status'] === 200 && $fallbackXpath->query('//*[@data-nfs-step]/p[@class="nfs-step-description"]')->length === 3, 'Step descriptions did not reach the installed renderer.');
foreach ($fallbackXpath->query('//*[@data-nfs-step]') as $node) {
    $description = $fallbackXpath->query('//*[@id="' . $node->getAttribute('aria-describedby') . '"]')->item(0);
    $assert($description?->textContent === 'Complete this stage <safely>.' && $fallbackXpath->query('.//safely', $node)->length === 0, 'Step description was not escaped or accessibly associated.');
}
file_put_contents($root . '/build/native-step-fallback-fixture.json', json_encode(['form_id' => $fallbackForm['id'], 'steps' => $stepIds, 'fields' => $stepFields], JSON_THROW_ON_ERROR));
echo "Native step fallback/reset: independent three-step fixture with persistent visibility controls and preserved final answer published.\n";
