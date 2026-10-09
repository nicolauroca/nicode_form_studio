<?php
declare(strict_types=1);

$resourceForm = $api('create', ['name' => 'HTTP source resource', 'alias' => 'source-resource-http-' . bin2hex(random_bytes(5))]);
$resourceDraft = $api('record', query: ['id' => $resourceForm['id']])['draft'];
$resourceParent = '93d24020-7d35-4bba-a401-9c8a62dd3fda'; $resourceField = '64da9060-bd36-44bb-872f-f4e53e4203bd';
$resourceDraft['elements'] = [['uuid' => $resourceParent, 'type' => 'field'], ['uuid' => $resourceField, 'type' => 'field']];
$resourceDraft['fields'] = [['uuid' => $resourceParent, 'name' => 'country', 'type' => 'text', 'config' => ['label' => 'Country', 'default' => 'ES']], ['uuid' => $resourceField, 'name' => 'city', 'type' => 'select', 'config' => ['label' => 'City'], 'source' => ['type' => 'static', 'dependencies' => [$resourceParent], 'config' => ['api_key' => 'private-http-source', 'options' => [['value' => 'MD', 'label' => 'Madrid', 'when' => [$resourceParent => 'ES']]]]]]];
$resourceRevision = $api('save', ['id' => $resourceForm['id'], 'revision' => 0, 'draft' => $resourceDraft])['revision'];
$captureSource = ['name' => 'HTTP reusable cities', 'form_id' => $resourceForm['id'], 'form_revision' => $resourceRevision, 'field_uuid' => $resourceField];
$api('datasource.capture', expected: 405); $api('datasource.capture', $captureSource, expected: 403, csrf: false);
$sourceRecordId = $api('datasource.capture', $captureSource)['id'];
$sourceRecord = $api('datasource.record', query: ['id' => $sourceRecordId]);
$assert(!str_contains(json_encode($sourceRecord), 'private-http-source') && isset($sourceRecord['definition']['parameters']['country']), 'Source resource exposed credentials or lost parameter.');
$destinationForm = $api('create', ['name' => 'HTTP source destination', 'alias' => 'source-destination-http-' . bin2hex(random_bytes(5))]);
$destinationDraft = $api('record', query: ['id' => $destinationForm['id']])['draft'];
$destinationParent = '7c7da3f0-5df2-4114-bf13-a953319bda52'; $destinationField = 'd0d0b85b-39c3-4cac-9689-c616b732fce3';
$destinationDraft['elements'] = [['uuid' => $destinationParent, 'type' => 'field'], ['uuid' => $destinationField, 'type' => 'field']];
$destinationDraft['fields'] = [['uuid' => $destinationParent, 'name' => 'country', 'type' => 'text', 'config' => ['label' => 'Country', 'default' => 'ES']], ['uuid' => $destinationField, 'name' => 'city', 'type' => 'select', 'config' => ['label' => 'City']]];
$destinationRevision = $api('save', ['id' => $destinationForm['id'], 'revision' => 0, 'draft' => $destinationDraft])['revision'];
$sourceBinding = ['id' => $sourceRecordId, 'revision' => 1, 'form_id' => $destinationForm['id'], 'field_uuid' => $destinationField, 'bindings' => ['country' => $destinationParent]];
$api('datasource.bind', $sourceBinding, expected: 403, csrf: false);
$boundSource = $api('datasource.bind', $sourceBinding)['source'];
$assert($boundSource['dependencies'] === [$destinationParent] && $boundSource['config']['options'][0]['when'] === [$destinationParent => 'ES'], 'Native source binding did not remap references.');
$destinationDraft['fields'][1]['source'] = $boundSource;
$destinationRevision = $api('save', ['id' => $destinationForm['id'], 'revision' => $destinationRevision, 'draft' => $destinationDraft])['revision'];
$sourcePublication = $api('publish', ['id' => $destinationForm['id'], 'revision' => $destinationRevision]);
$resourceConfigure = ['id' => $sourceRecordId, 'revision' => 1, 'name' => 'Disabled HTTP source', 'enabled' => false];
$api('datasource.configure', $resourceConfigure, expected: 403, csrf: false); $api('datasource.configure', $resourceConfigure);
$api('datasource.configure', $resourceConfigure, expected: 409); $api('datasource.bind', $sourceBinding, expected: 409);
$sourceBinding['revision'] = 2; $api('datasource.bind', $sourceBinding, expected: 403);
$sourceHistoric = $api('preview', query: ['id' => $destinationForm['id'], 'version' => $sourcePublication['version_id']]);
$assert(str_contains($sourceHistoric['html'], 'Madrid'), 'Disabling resource changed the published source copy.');
foreach (['datasources', 'datasource&id=' . $sourceRecordId] as $view) { $sourceView = $request($base . '?option=com_nicode_form_studio&view=' . $view); $assert($sourceView['status'] === 200 && str_contains($sourceView['body'], 'Disabled HTTP source'), 'Native source resource view missing.'); }
file_put_contents($root . '/build/native-source-resource-fixture.json', json_encode(['source_form' => $resourceForm['id'], 'destination_form' => $destinationForm['id'], 'resource_id' => $sourceRecordId], JSON_THROW_ON_ERROR));
echo "Native source resources HTTP: portable capture, named binding, CSRF/method checks, revisions, disabled-resource rejection, published-copy isolation and views passed.\n";
