<?php
declare(strict_types=1);

$validationForm = $api('create', ['name' => 'HTTP confirmation acceptance', 'alias' => 'validation-http-' . bin2hex(random_bytes(6))]);
$validationDraft = $api('record', query: ['id' => $validationForm['id']])['draft'];
$leftField = '49deaa08-a9dd-4ef5-ad8d-33e68de53cba'; $rightField = 'a83a0e40-f23a-4cf8-8e9c-9720fca03c76';
$validationDraft['elements'] = $validationDraft['fields'] = [];
foreach ([$leftField => 'original', $rightField => 'confirmation'] as $uuid => $name) {
    $validationDraft['elements'][] = ['uuid' => $uuid, 'type' => 'field', 'parent_uuid' => null];
    $validationDraft['fields'][] = ['uuid' => $uuid, 'name' => $name, 'type' => 'email', 'config' => ['label' => ucfirst($name), 'required' => true]];
}
$validationDraft['validators'] = [['type' => 'confirmation', 'config' => ['fields' => [$leftField, $rightField]]]];
$validationDraft['fields'][0]['config'] += ['admin_label' => 'private-authoring-marker', 'description' => '<b>Public description</b>', 'help' => '<em>Public help</em>', 'css_class' => 'nfs-custom-contact', 'autocomplete' => 'email', 'inputmode' => 'email'];
$validationDraft['fields'][0]['prefill'] = ['type' => 'query', 'key' => 'initial_email'];
$copiedField = '0f327373-5a5c-4248-b65f-78f86a0a1b68';
$validationDraft['elements'][] = ['uuid' => $copiedField, 'type' => 'field', 'parent_uuid' => null];
$validationDraft['fields'][] = ['uuid' => $copiedField, 'name' => 'server_copy', 'type' => 'email', 'config' => ['label' => 'Server copy', 'readonly' => true], 'prefill' => ['type' => 'field', 'field' => $leftField]];
$profileField = '0ac31db2-2d2b-4094-bdc6-968061fab8d6';
$validationDraft['elements'][] = ['uuid' => $profileField, 'type' => 'field', 'parent_uuid' => null];
$validationDraft['fields'][] = ['uuid' => $profileField, 'name' => 'profile_name', 'type' => 'text', 'config' => ['label' => 'Profile username', 'readonly' => true], 'prefill' => ['type' => 'user', 'property' => 'username']];
$lockedSelection = '8fcf289d-c061-4604-a4a4-dd43a9f7ee55'; $lockedBoolean = 'b1182388-6cf9-4e19-a0b2-2d27271e34e3';
foreach ([$lockedSelection, $lockedBoolean] as $uuid) { $validationDraft['elements'][] = ['uuid' => $uuid, 'type' => 'field', 'parent_uuid' => null]; }
$validationDraft['fields'][] = ['uuid' => $lockedSelection, 'name' => 'approved_country', 'type' => 'select', 'config' => ['label' => 'Read-only country', 'readonly' => true, 'default' => 'ES'], 'options' => [['value' => 'ES', 'label' => 'Spain'], ['value' => 'FR', 'label' => 'France']]];
$validationDraft['fields'][] = ['uuid' => $lockedBoolean, 'name' => 'approved_flag', 'type' => 'checkbox', 'config' => ['label' => 'Read-only flag', 'readonly' => true, 'default' => true]];
$consentHistoryField = '66c5ec34-0d2d-4b87-a03f-df80215d2e59';
$validationDraft['elements'][] = ['uuid' => $consentHistoryField, 'type' => 'field', 'parent_uuid' => null];
$validationDraft['fields'][] = ['uuid' => $consentHistoryField, 'name' => 'historical_consent', 'type' => 'consent', 'config' => ['label' => 'Historical consent <script> text']];
$validationRevision = $api('save', ['id' => $validationForm['id'], 'revision' => 0, 'draft' => $validationDraft])['revision'];
$api('publish', ['id' => $validationForm['id'], 'revision' => $validationRevision]);
$validationEditor = $request($base . '?option=com_nicode_form_studio&view=editor&id=' . $validationForm['id']);
$assert(str_contains($validationEditor['body'], 'data-nfs-validators'), 'Native validator editor is missing.');
$visitorJar = $root . '/build/validator-cookie-' . bin2hex(random_bytes(6)) . '.txt';
$visitorRequest = static function (string $url, ?array $post = null, array $headers = []) use ($visitorJar): array {
    if (!str_starts_with($url, 'http://127.0.0.1:13371/index.php')) { throw new RuntimeException('Unexpected validator test destination.'); }
    $handle = curl_init($url);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_COOKIEJAR => $visitorJar, CURLOPT_COOKIEFILE => $visitorJar, CURLOPT_PROXY => '']);
    if ($headers !== []) { curl_setopt($handle, CURLOPT_HTTPHEADER, $headers); }
    if ($post !== null) { curl_setopt($handle, CURLOPT_POST, true); curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = curl_exec($handle); if (!is_string($body)) { throw new RuntimeException('Validator HTTP connection failed.'); }
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE); curl_setopt($handle, CURLOPT_COOKIELIST, 'FLUSH');
    return ['status' => $status, 'body' => $body];
};
try {
    $validationUrl = 'http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id=' . $validationForm['id'];
    $validationPage = $visitorRequest($validationUrl . '&initial_email=initial%40example.test&unconfigured=ignored');
    $xpath = $dom($validationPage['body']); $formNode = $xpath->query('//form[@data-nfs-form]')->item(0);
    $assert($validationPage['status'] === 200 && $formNode instanceof DOMElement, 'Confirmation form failed to render.');
    foreach (['&lt;b&gt;Public description&lt;/b&gt;', '&lt;em&gt;Public help&lt;/em&gt;', 'nfs-custom-contact', 'autocomplete="email"', 'inputmode="email"'] as $expected) { $assert(str_contains($validationPage['body'], $expected), 'Common field presentation missing.'); }
    $assert(!str_contains($validationPage['body'], 'private-authoring-marker') && !str_contains($validationPage['body'], '<b>Public description</b>'), 'Administrative label or unsafe markup leaked into public rendering.');
    foreach ([$lockedSelection, $lockedBoolean] as $locked) { $assert($xpath->query('.//*[@data-nfs-input="' . $locked . '"]', $formNode)->item(0)?->hasAttribute('disabled') === true, 'Read-only native control remains editable.'); }
    foreach ([$leftField, $copiedField] as $prefilled) { $assert($xpath->query('.//input[@data-nfs-input="' . $prefilled . '"]', $formNode)->item(0)?->getAttribute('value') === 'initial@example.test', 'Configured query/field prefill was not rendered.'); }
    $invalidPrefill = $visitorRequest($validationUrl . '&initial_email=invalid-email');
    $assert($invalidPrefill['status'] === 200 && !str_contains($invalidPrefill['body'], 'value="invalid-email"'), 'Invalid URL prefill was displayed or disabled the form.');
    $validationPost = ['format' => 'json'];
    foreach ($xpath->query('.//input[@type="hidden"]', $formNode) as $input) { $validationPost[$input->getAttribute('name')] = $input->getAttribute('value'); }
    $destination = 'http://127.0.0.1:13371' . $formNode->getAttribute('action');
    $validationPost['nfs'] = [$leftField => 'original@example.test', $rightField => 'different@example.test', $copiedField => 'forged@example.test'];
    $validationPost['nfs'][$lockedSelection] = 'FR'; $validationPost['nfs'][$lockedBoolean] = '0';
    $validationPost['nfs'][$consentHistoryField] = '1';
    $rejected = $visitorRequest($destination, $validationPost); $result = json_decode($rejected['body'], true, 512, JSON_THROW_ON_ERROR);
    $assert($rejected['status'] === 422 && $result['category'] === 'validation_error' && isset($result['errors'][$leftField], $result['errors'][$rightField]), 'Direct POST bypassed confirmation validation.');
    $validationPost['nfs'][$rightField] = $validationPost['nfs'][$leftField];
    $accepted = $visitorRequest($destination, $validationPost); $result = json_decode($accepted['body'], true, 512, JSON_THROW_ON_ERROR);
    $assert($accepted['status'] === 200 && $result['category'] === 'success', 'Matching confirmation could not be submitted after correction.');
    $prefillDb = new PDO('mysql:host=127.0.0.1;port=13367;dbname=formstudio_joomla;charset=utf8mb4', $configuration->user, $configuration->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $prefillStatement = $prefillDb->prepare('SELECT canonical_payload FROM j6_nicode_form_studio_submissions WHERE form_id = ? AND uuid = ?'); $prefillStatement->execute([$validationForm['id'], $result['reference']]);
    $prefillPayload = json_decode($prefillStatement->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    $assert($prefillPayload['values'][$copiedField] === 'original@example.test', 'Read-only prefill accepted a forged browser value.');
    $assert($prefillPayload['values'][$lockedSelection] === 'ES' && $prefillPayload['values'][$lockedBoolean] === true, 'Forged read-only choice replaced server defaults.');
    $assert($prefillPayload['values'][$profileField] === null, 'Anonymous prefill exposed a user profile.');
    $consentIdQuery = $prefillDb->prepare('SELECT id FROM j6_nicode_form_studio_submissions WHERE form_id = ? AND uuid = ?'); $consentIdQuery->execute([$validationForm['id'], $result['reference']]); $consentResponseId = (int) $consentIdQuery->fetchColumn();
    $consentDetail = $submissionApi('record', ['form_id' => $validationForm['id'], 'id' => $consentResponseId]);
    $assert($consentDetail['consents'][$consentHistoryField]['accepted'] === true && $consentDetail['consents'][$consentHistoryField]['text'] === 'Historical consent <script> text', 'Native historical consent lost accepted text.');
    $consentPage = $request($base . '?' . http_build_query(['option' => 'com_nicode_form_studio', 'view' => 'submission', 'form_id' => $validationForm['id'], 'id' => $consentResponseId]));
    $assert($consentPage['status'] === 200 && str_contains($consentPage['body'], 'data-nfs-consents') && str_contains($consentPage['body'], 'Historical consent &lt;script&gt; text') && !str_contains($consentPage['body'], 'Historical consent <script> text'), 'Consent history rendering lost text or markup safety.');
    file_put_contents($root . '/build/native-consent-fixture.json', json_encode(['form_id' => $validationForm['id'], 'id' => $consentResponseId], JSON_THROW_ON_ERROR));
    $loginPost = [];
    foreach ($xpath->query('//form[.//input[@name="username"]]//input[@type="hidden"]') as $input) { $loginPost[$input->getAttribute('name')] = $input->getAttribute('value'); }
    $profileCredentials = json_decode(file_get_contents($root . '/build/joomla-test.json'), true, 512, JSON_THROW_ON_ERROR);
    $loginPost['username'] = $profileCredentials['username']; $loginPost['password'] = $profileCredentials['password'];
    $profileLogin = $visitorRequest('http://127.0.0.1:13371/index.php', $loginPost); unset($loginPost);
    $assert(in_array($profileLogin['status'], [302, 303], true), 'Native frontend profile login failed.');
    $profilePage = $visitorRequest($validationUrl); $profileXpath = $dom($profilePage['body']); $profileNode = $profileXpath->query('//form[@data-nfs-form]')->item(0);
    $assert($profileXpath->query('.//input[@data-nfs-input="' . $profileField . '"]', $profileNode)->item(0)?->getAttribute('value') === $profileCredentials['username'], 'Authenticated Joomla profile was not used for prefill.');
    $profilePost = ['format' => 'json', 'nfs' => [$leftField => 'signed-in@example.test', $rightField => 'signed-in@example.test', $profileField => 'forged-profile']];
    foreach ($profileXpath->query('.//input[@type="hidden"]', $profileNode) as $input) { $profilePost[$input->getAttribute('name')] = $input->getAttribute('value'); }
    $profileAccepted = $visitorRequest($destination, $profilePost); $profileResult = json_decode($profileAccepted['body'], true, 512, JSON_THROW_ON_ERROR);
    $assert($profileAccepted['status'] === 200 && $profileResult['category'] === 'success', 'Authenticated prefill submission failed.');
    $prefillStatement->execute([$validationForm['id'], $profileResult['reference']]); $profilePayload = json_decode($prefillStatement->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    $assert($profilePayload['values'][$profileField] === $profileCredentials['username'], 'Forged POST replaced the authenticated profile.'); unset($profileCredentials);
    file_put_contents($root . '/build/native-validator-fixture.json', json_encode(['form_id' => $validationForm['id'], 'left' => $leftField, 'right' => $rightField], JSON_THROW_ON_ERROR));
    require __DIR__ . '/http-admin-temporal.php';
    require __DIR__ . '/http-admin-range.php';
    require __DIR__ . '/http-admin-text.php';
    require __DIR__ . '/http-admin-selections.php';
    require __DIR__ . '/http-admin-request-metadata.php';
    require __DIR__ . '/http-admin-derived-fields.php';
    require __DIR__ . '/http-admin-action-conditions.php';
    require __DIR__ . '/http-admin-action-failures.php';
    require __DIR__ . '/http-admin-advisories.php';
    require __DIR__ . '/http-admin-publication-activation.php';
    require __DIR__ . '/http-admin-boolean-comparison.php';
    require __DIR__ . '/http-admin-conditional-steps.php';
    require __DIR__ . '/http-admin-presentation.php';
    require __DIR__ . '/http-admin-layout.php';
    require __DIR__ . '/http-admin-large-form.php';
    require __DIR__ . '/http-admin-repeated-public.php';
} finally { if (is_file($visitorJar)) { unlink($visitorJar); } }
echo "Native validation HTTP: editor, saved comparison, direct POST mismatch rejection, corrected submission, allowed query prefill and authoritative field copy passed.\n";
