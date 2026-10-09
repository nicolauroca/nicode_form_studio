<?php
declare(strict_types=1);

$templateOwner = $api('create', ['name' => 'HTTP template source', 'alias' => 'template-source-' . bin2hex(random_bytes(6))]);
$templateDraft = $api('record', query: ['id' => $templateOwner['id']])['draft']; $templateField = '6abc144f-f4ec-46a5-b8f0-49e879a2c792';
$templateDraft['elements'] = [['uuid' => $templateField, 'type' => 'field', 'parent_uuid' => null]];
$templateDraft['fields'] = [['uuid' => $templateField, 'name' => 'contact', 'type' => 'text', 'config' => ['label' => 'Contact name']]];
$templateSaved = $api('save', ['id' => $templateOwner['id'], 'revision' => 0, 'draft' => $templateDraft]);
$api('template.create', expected: 405);
$api('template.create', ['name' => 'Blocked'], expected: 403, csrf: false);
$emailResource = $api('template.create', ['name' => 'HTTP reusable email']);
$emailSave = ['id' => $emailResource['id'], 'revision' => 0, 'name' => 'HTTP reusable email', 'language' => 'es-ES', 'content' => ['subject' => '{{form.name}}', 'body_text' => 'Hola {{input.contact.value}}', 'body_html' => '']];
$emailSaved = $api('template.save', $emailSave);
$emailRecord = $api('template.record', query: ['id' => $emailResource['id'], 'kind' => 'email']);
$assert($emailRecord['revision'] === 1 && $emailRecord['parameters'] === ['contact'], 'Native email template failed to persist named tokens.');
$binding = ['id' => $emailResource['id'], 'revision' => 1, 'form_id' => $templateOwner['id'], 'bindings' => ['contact' => $templateField]];
$api('template.bind', $binding, expected: 403, csrf: false);
$bound = $api('template.bind', $binding);
$assert($bound['config']['body_text'] === 'Hola {{field.' . $templateField . '.value}}' && $bound['config']['template']['revision'] === 1, 'Native email binding lost field identity or provenance.');
$api('template.save', $emailSave, expected: 409);
$templateCapture = ['name' => 'HTTP reusable form', 'form_id' => $templateOwner['id'], 'form_revision' => $templateSaved['revision']];
$api('template.capture', $templateCapture, expected: 403, csrf: false);
$formResource = $api('template.capture', $templateCapture);
$formTemplateChoices = ['id' => $formResource['id'], 'revision' => $formResource['revision'], 'name' => 'HTTP template draft', 'alias' => 'template-draft-' . bin2hex(random_bytes(6))];
$review = $api('template.preview', $formTemplateChoices);
$createdFromTemplate = $api('template.apply', $formTemplateChoices + ['review_token' => $review['review_token'], 'acknowledge_review' => true]);
$createdRecord = $api('record', query: ['id' => $createdFromTemplate['id']]);
$assert($createdRecord['form']['state'] === 'draft' && $createdRecord['form']['published_version_id'] === null && $createdRecord['draft']['fields'][0]['uuid'] !== $templateField, 'Native template instantiation failed to create an independent draft.');
foreach (['templates&kind=email', 'templates&kind=form', 'emailtemplate&id=' . $emailResource['id']] as $view) {
    $page = $request($base . '?option=com_nicode_form_studio&view=' . $view);
    $assert($page['status'] === 200 && str_contains($page['body'], 'data-nfs-'), 'Native template view unavailable.');
}
echo "Native templates HTTP: create/save, named token binding, revision conflicts, method/CSRF, portable capture, signed independent drafts and native resource views passed.\n";
