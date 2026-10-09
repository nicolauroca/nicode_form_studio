<?php
declare(strict_types=1);
$presentationForm = $api('create', ['name' => 'Presentation acceptance', 'alias' => 'presentation-' . bin2hex(random_bytes(6))]);
$presentationDraft = $api('record', query: ['id' => $presentationForm['id']])['draft'];
$presentationDraft['fields'] = $presentationDraft['elements'] = []; $presentationIds = [];
foreach (['heading', 'subheading', 'paragraph', 'notice', 'safe-html', 'separator', 'spacer'] as $type) {
    $uuid = 'e68faf85-90e7-490e-a0ec-904fe878163' . count($presentationIds); $presentationIds[$type] = $uuid;
    $text = in_array($type, ['separator', 'spacer'], true) ? '' : $type . ' <literal>';
    if ($type === 'safe-html') { $text = '<p><strong>Allowed emphasis</strong> <a href="https://example.test/" title="Reference">Safe link</a></p><script>alert(1)</script><img src=x onerror="alert(1)"><svg onload="alert(1)"></svg><a href="javascript:alert(1)" onclick="alert(1)" style="color:red">Unsafe link</a><input name="injected">'; }
    $presentationDraft['elements'][] = ['uuid' => $uuid, 'type' => $type, 'parent_uuid' => null, 'text' => $text];
}
$presentationAnswer = 'e68faf85-90e7-490e-a0ec-904fe8781637';
$presentationDraft['elements'][] = ['uuid' => $presentationAnswer, 'type' => 'field', 'parent_uuid' => null];
$presentationDraft['fields'][] = ['uuid' => $presentationAnswer, 'name' => 'answer', 'type' => 'text', 'config' => ['label' => 'Your answer', 'required' => true]];
$presentationRevision = $api('save', ['id' => $presentationForm['id'], 'revision' => 0, 'draft' => $presentationDraft])['revision'];
$api('publish', ['id' => $presentationForm['id'], 'revision' => $presentationRevision]);
$presentationPreview = $api('preview', query: ['id' => $presentationForm['id']]);
$previewXpath = $dom($presentationPreview['html']);
$previewHtmlNode = $previewXpath->query('//*[@data-nfs-element="' . $presentationIds['safe-html'] . '"]')->item(0);
$assert($previewHtmlNode instanceof DOMElement && $previewXpath->query('.//strong', $previewHtmlNode)->length === 1 && $previewXpath->query('.//script|.//img|.//svg|.//input|.//*[@onclick or @onerror or @onload or @style]', $previewHtmlNode)->length === 0, 'Administrator preview did not apply the same safe HTML policy.');
$presentationPage = $visitorRequest('http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id=' . $presentationForm['id']);
$presentationXpath = $dom($presentationPage['body']); $presentationNode = $presentationXpath->query('//form[@data-nfs-form]')->item(0);
$assert($presentationPage['status'] === 200 && $presentationNode instanceof DOMElement, 'Presentation fixture did not render.');
foreach ($presentationIds as $type => $uuid) {
    $element = $presentationXpath->query('.//*[@data-nfs-element="' . $uuid . '"]', $presentationNode)->item(0);
    $assert($element instanceof DOMElement, 'Missing presentation element: ' . $type);
    $assert($element->tagName === match ($type) { 'heading' => 'h2', 'subheading' => 'h3', 'paragraph' => 'p', default => 'div' }, 'Presentation semantics changed.');
    if (in_array($type, ['heading', 'subheading', 'paragraph', 'notice'], true)) { $assert($element->textContent === $type . ' <literal>' && $presentationXpath->query('.//*', $element)->length === 0, 'Plain presentation text became markup.'); }
    if ($type === 'separator') { $assert($presentationXpath->query('./hr', $element)->length === 1, 'Separator lost native semantics.'); }
    if ($type === 'safe-html') {
        $assert($presentationXpath->query('.//strong', $element)->item(0)?->textContent === 'Allowed emphasis', 'Safe HTML lost allowed formatting.');
        $assert($presentationXpath->query('.//a[@href="https://example.test/"]', $element)->length === 1, 'Safe HTML lost an allowed link.');
        $assert($presentationXpath->query('.//script|.//img|.//svg|.//input|.//*[@onclick or @onerror or @onload or @style]', $element)->length === 0, 'Safe HTML retained executable or interactive markup.');
        foreach ($presentationXpath->query('.//a[@href]', $element) as $link) { $assert(!str_contains(strtolower($link->getAttribute('href')), 'javascript:'), 'Unsafe link protocol survived filtering.'); }
    }
}
$presentationPost = ['format' => 'json', 'nfs' => [$presentationAnswer => 'accepted', ...array_fill_keys(array_values($presentationIds), 'forged presentation answer')]];
foreach ($presentationXpath->query('.//input[@type="hidden"]', $presentationNode) as $input) { $presentationPost[$input->getAttribute('name')] = $input->getAttribute('value'); }
$presentationResponse = $visitorRequest('http://127.0.0.1:13371' . $presentationNode->getAttribute('action'), $presentationPost);
$presentationResult = json_decode($presentationResponse['body'], true, flags: JSON_THROW_ON_ERROR);
$assert($presentationResponse['status'] === 200 && $presentationResult['category'] === 'success', 'Presentation elements interfered with submission.');
$prefillStatement->execute([$presentationForm['id'], $presentationResult['reference']]); $presentationPayload = json_decode($prefillStatement->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
$assert($presentationPayload['values'] === [$presentationAnswer => 'accepted'], 'Presentation elements became response fields.');
file_put_contents($root . '/build/native-presentation-fixture.json', json_encode(['form_id' => $presentationForm['id'], 'elements' => $presentationIds], JSON_THROW_ON_ERROR));
echo "Native presentation: seven types, semantic headings/separator, escaped text, allowlisted HTML and non-field canonical exclusion passed.\n";
