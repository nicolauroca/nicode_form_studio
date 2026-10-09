<?php
declare(strict_types=1);
$textForm = $api('create', ['name' => 'Text type acceptance', 'alias' => 'text-types-' . bin2hex(random_bytes(6))]);
$textDraft = $api('record', query: ['id' => $textForm['id']])['draft'];
$textDraft['security']['rate_limit'] = 100; // This fixture intentionally sends more than 30 invalid/valid cases.
$textDraft['fields'] = $textDraft['elements'] = []; $textIds = []; $textValues = [];
foreach (['email' => 'User+tag@example.test', 'url' => 'https://example.test/path?x=1#section', 'color' => '#12ab34', 'text' => '😀', 'textarea' => '😀', 'telephone' => '+34 600 123 456', 'search' => '  term  ', 'password' => ' x '] as $type => $value) {
    $uuid = '84db6029-bc97-42dd-a07d-47e94ed5cf7' . count($textIds); $textIds[$type] = $uuid; $textValues[$uuid] = $value;
    $textDraft['elements'][] = ['uuid' => $uuid, 'type' => 'field', 'parent_uuid' => null];
    $config = ['label' => $type, 'required' => true];
    if (in_array($type, ['text', 'textarea'], true)) { $config += ['min_length' => 1, 'max_length' => 1]; }
    if ($type === 'telephone') { $config += ['min_length' => 3, 'max_length' => 32, 'autocomplete' => 'tel', 'inputmode' => 'tel']; }
    if ($type === 'search') { $config += ['trim' => false, 'max_length' => 10, 'autocomplete' => 'off', 'inputmode' => 'search']; }
    if ($type === 'password') { $config += ['min_length' => 3, 'max_length' => 3]; }
    $textDraft['fields'][] = ['uuid' => $uuid, 'name' => $type, 'type' => $type, 'config' => $config];
}
$textRevision = $api('save', ['id' => $textForm['id'], 'revision' => 0, 'draft' => $textDraft])['revision'];
$api('publish', ['id' => $textForm['id'], 'revision' => $textRevision]);
$textPage = $visitorRequest('http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id=' . $textForm['id']);
$textXpath = $dom($textPage['body']); $textNode = $textXpath->query('//form[@data-nfs-form]')->item(0);
$assert($textPage['status'] === 200 && $textNode instanceof DOMElement, 'Text type fixture failed to render.');
foreach (['text', 'textarea'] as $type) {
    $control = $textXpath->query('.//*[@data-nfs-input="' . $textIds[$type] . '"]', $textNode)->item(0);
    $assert($control instanceof DOMElement && !$control->hasAttribute('maxlength') && !$control->hasAttribute('minlength'), 'Native UTF16 length constraints would truncate Unicode input.');
}
foreach (['email' => 'email', 'url' => 'url', 'color' => 'color', 'text' => 'text', 'telephone' => 'tel', 'search' => 'search', 'password' => 'password'] as $type => $htmlType) {
    $control = $textXpath->query('.//input[@data-nfs-input="' . $textIds[$type] . '"]', $textNode)->item(0);
    $assert($control instanceof DOMElement && $control->getAttribute('type') === $htmlType, 'Text provider lost its native input type: ' . $type);
    if (in_array($type, ['telephone', 'search'], true)) {
        $assert($control->getAttribute('inputmode') === $htmlType && $control->getAttribute('autocomplete') === ($type === 'telephone' ? 'tel' : 'off'), 'Configured text input hints were lost.');
    }
}
$textPost = ['format' => 'json', 'nfs' => $textValues];
foreach ($textXpath->query('.//input[@type="hidden"]', $textNode) as $input) { $textPost[$input->getAttribute('name')] = $input->getAttribute('value'); }
$textDestination = 'http://127.0.0.1:13371' . $textNode->getAttribute('action');
foreach (['email' => ['invalid', 'a@localhost', "user@example.test\r\nBcc:another@example.test"], 'url' => ['ftp://example.test/', 'javascript:alert(1)', 'https://mañana.test/', 'https://'], 'color' => ['red', '#abc', '#11223344'], 'text' => ['😀😀', "n\u{0303}"], 'textarea' => ['ab'], 'telephone' => ['', '12', str_repeat('1', 33)], 'search' => ['', str_repeat('a', 11)], 'password' => ['', 'x', ' xxxx ']] as $type => $invalids) {
    foreach ($invalids as $invalid) {
        $invalidPost = $textPost; $invalidPost['nfs'][$textIds[$type]] = $invalid;
        $response = $visitorRequest($textDestination, $invalidPost); $result = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        $assert($response['status'] === 422 && isset($result['errors'][$textIds[$type]]), 'Direct POST bypassed text type validation.');
    }
}
$response = $visitorRequest($textDestination, $textPost); $result = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
$assert($response['status'] === 200 && $result['category'] === 'success', 'Valid text type submission failed.');
$prefillStatement->execute([$textForm['id'], $result['reference']]); $textPayload = json_decode($prefillStatement->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
foreach ($textValues as $uuid => $value) {
    if ($uuid === $textIds['password']) { $assert(!array_key_exists($uuid, $textPayload['values']), 'Password persisted in the canonical response.'); }
    else { $assert($textPayload['values'][$uuid] === $value, 'Accepted text value was rewritten.'); }
}
file_put_contents($root . '/build/native-text-fixture.json', json_encode(['form_id' => $textForm['id'], 'fields' => $textIds], JSON_THROW_ON_ERROR));
// Each accepted response consumes its own attempt; fetch fresh forms so this
// also proves these values traverse the actual native ingress and storage.
foreach (['"user"@example.test', 'user@[192.0.2.1]', 'user@[IPv6:2001:db8::1]'] as $specialEmail) {
    $specialPage = $visitorRequest('http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id=' . $textForm['id']);
    $specialXpath = $dom($specialPage['body']); $specialNode = $specialXpath->query('//form[@data-nfs-form]')->item(0);
    $specialPost = ['format' => 'json', 'nfs' => $textValues]; $specialPost['nfs'][$textIds['email']] = $specialEmail;
    foreach ($specialXpath->query('.//input[@type="hidden"]', $specialNode) as $input) { $specialPost[$input->getAttribute('name')] = $input->getAttribute('value'); }
    foreach (['"unterminated@example.test', 'user@[999.0.0.1]', 'user@[IPv6:invalid]'] as $invalidEmail) {
        $invalidPost = $specialPost; $invalidPost['nfs'][$textIds['email']] = $invalidEmail;
        $invalidResponse = $visitorRequest($textDestination, $invalidPost); $invalidResult = json_decode($invalidResponse['body'], true, 512, JSON_THROW_ON_ERROR);
        $assert($invalidResponse['status'] === 422 && isset($invalidResult['errors'][$textIds['email']]), 'Special email syntax validation failed: HTTP ' . $invalidResponse['status'] . ', category ' . ($invalidResult['category'] ?? 'missing') . ', code ' . ($invalidResult['code'] ?? 'missing'));
    }
    $specialResponse = $visitorRequest($textDestination, $specialPost); $specialResult = json_decode($specialResponse['body'], true, 512, JSON_THROW_ON_ERROR);
    $assert($specialResponse['status'] === 200 && $specialResult['category'] === 'success', 'Valid special email syntax rejected.');
    $prefillStatement->execute([$textForm['id'], $specialResult['reference']]); $specialPayload = json_decode($prefillStatement->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    $assert($specialPayload['values'][$textIds['email']] === $specialEmail, 'Special email identity changed during persistence.');
}
echo "Native text fields: all eight controls, input hints, direct POST rejection, exact text/Unicode persistence, untrimmed search/password validation and password storage exclusion passed.\n";
