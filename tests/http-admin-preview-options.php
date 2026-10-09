<?php
declare(strict_types=1);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../tools/prepare-dynamic-options.php'), $previewPreparation, $previewPreparationExit);
if ($previewPreparationExit !== 0) { throw new RuntimeException('Native preview provider preparation failed.'); }
$previewForm = $api('create', ['name' => 'Remote preview acceptance', 'alias' => 'remote-preview-' . bin2hex(random_bytes(6))]);
$previewId = $previewForm['id'];
$previewDraft = $api('record', query: ['id' => $previewId])['draft'];
$parentId = '81e749aa-9fb3-4a66-b320-6b0b89f50c22'; $choiceId = '791a4bc3-1f67-4d60-8859-2bd0264c0539';
$previewDraft['elements'] = [['uuid' => $parentId, 'type' => 'field', 'parent_uuid' => null], ['uuid' => $choiceId, 'type' => 'field', 'parent_uuid' => null]];
$previewDraft['fields'] = [
    ['uuid' => $parentId, 'type' => 'text', 'name' => 'country', 'config' => ['label' => 'Country']],
    ['uuid' => $choiceId, 'type' => 'select', 'name' => 'province', 'config' => ['label' => 'Province', 'readonly' => true], 'source' => ['type' => 'fixture.department', 'dependencies' => [$parentId], 'config' => ['prefix' => 'Preview', 'country' => $parentId, 'defaults' => true]]],
];
$savedPreview = $api('save', ['id' => $previewId, 'revision' => 0, 'draft' => $previewDraft]);
$payloadPreview = ['id' => $previewId, 'revision' => $savedPreview['revision'], 'values' => [$parentId => 'ES'], 'locale' => 'en-GB'];
$api('previewOptions', expected: 405);
$api('previewOptions', $payloadPreview, expected: 403, csrf: false);
$resultPreview = $api('previewOptions', $payloadPreview);
$assert(array_column($resultPreview['options'][$choiceId], 'value') === ['MD', 'BC'], 'Draft preview source ignored dependency.');
$assert($resultPreview['options'][$choiceId][0]['default'] === true && !str_contains(json_encode($resultPreview), 'not-public'), 'Preview option defaults missing or private metadata exposed.');
$assert($api('previewOptions', array_replace($payloadPreview, ['values' => [$parentId => 'FR']]))['options'][$choiceId] === [], 'Draft preview retained stale options.');
$api('previewOptions', array_replace($payloadPreview, ['revision' => 0]), expected: 409);
$api('previewOptions', array_replace($payloadPreview, ['locale' => '../bad']), expected: 422);
$api('previewOptions', array_replace($payloadPreview, ['values' => 'bad']), expected: 422);
$publishedPreview = $api('publish', ['id' => $previewId, 'revision' => $savedPreview['revision']]);
$previewDraft['fields'][1]['source']['config']['country'] = $choiceId;
// Save an invalid draft deliberately: historical options must use the snapshot.
$api('save', ['id' => $previewId, 'revision' => $publishedPreview['revision'], 'draft' => $previewDraft]);
$historicPreview = $api('previewOptions', $payloadPreview + ['version' => $publishedPreview['version_id']]);
$assert($historicPreview === $resultPreview, 'Historical preview options read the changed draft.');
$api('previewOptions', $payloadPreview + ['version' => 2147483647], expected: 404);
$otherPreview = $api('create', ['name' => 'Other preview owner', 'alias' => 'other-preview-' . bin2hex(random_bytes(6))]);
$otherDraft = $api('record', query: ['id' => $otherPreview['id']])['draft'];
$otherDraft['elements'] = $previewDraft['elements']; $otherDraft['fields'] = $previewDraft['fields'];
$otherDraft['fields'][1]['source']['config']['country'] = $parentId;
$otherSaved = $api('save', ['id' => $otherPreview['id'], 'revision' => 0, 'draft' => $otherDraft]);
$otherPublished = $api('publish', ['id' => $otherPreview['id'], 'revision' => $otherSaved['revision']]);
$api('previewOptions', $payloadPreview + ['version' => $otherPublished['version_id']], expected: 404);
echo "Native preview options: POST/CSRF, draft revision, safe defaults, dependencies, malformed input and historical isolation passed.\n";
echo "Native preview options: existing snapshot owned by another form rejected.\n";

$repeatPreview=$api('create',['name'=>'Repeated preview acceptance','alias'=>'repeat-preview-'.bin2hex(random_bytes(6))]);
$repeatDraft=$api('record',query:['id'=>$repeatPreview['id']])['draft'];
$repeatGroup='31e749aa-9fb3-4a66-b320-6b0b89f50c22';
$repeatDraft['elements']=[['uuid'=>$repeatGroup,'type'=>'repeatable-group','repeat'=>['min'=>2,'max'=>3]],...$otherDraft['elements']];
foreach ($repeatDraft['elements'] as &$element) { if ($element['type']==='field') { $element['parent_uuid']=$repeatGroup; } } unset($element);
$repeatDraft['fields']=$otherDraft['fields'];
$repeatSaved=$api('save',['id'=>$repeatPreview['id'],'revision'=>0,'draft'=>$repeatDraft]);
$rendered=$api('preview',query:['id'=>$repeatPreview['id']]);
$assert(is_string($rendered['html']) && $rendered['diagnostics']===[],'Valid repeated preview failed compilation.');
$previewDom=$dom($rendered['html']);
$declarations=json_decode($previewDom->query('//input[@name="nfs_instances"]')->item(0)->getAttribute('value'),true,512,JSON_THROW_ON_ERROR);
$assert(count($declarations[$repeatGroup])===2 && $declarations[$repeatGroup][0]!==$declarations[$repeatGroup][1],'Preview did not create distinct minimum row identities.');
$assert($previewDom->query('//button[@data-nfs-submit and @disabled]')->length===1 && $previewDom->query('//input[@name="attempt"]')->item(0)->getAttribute('value')==='','Repeated preview acquired submit capability.');
$assert($previewDom->query('//button[@data-nfs-row-change and @type="button"]')->length===3,'Sandboxed preview row editing must not depend on native form submission.');
[$rowOne,$rowTwo]=$declarations[$repeatGroup];
$key=static fn($row,$field)=>$repeatGroup.'/'.$row.'/'.$field;
$repeatPayload=['id'=>$repeatPreview['id'],'revision'=>$repeatSaved['revision'],'instances'=>$declarations,'values'=>[$key($rowOne,$parentId)=>'ES',$key($rowTwo,$parentId)=>'FR']];
$rowOptions=$api('previewOptions',$repeatPayload);
$assert(array_column($rowOptions['options'][$key($rowOne,$choiceId)],'value')===['MD','BC'] && $rowOptions['options'][$key($rowTwo,$choiceId)]===[],'Repeated preview options crossed row scopes.');
$api('previewOptions',array_replace($repeatPayload,['instances'=>null]),expected:422);
$api('previewOptions',array_replace($repeatPayload,['instances'=>'bad']),expected:422);
$api('previewOptions',array_replace($repeatPayload,['values'=>[$parentId=>'ES']]),expected:422);
$api('previewOptions',array_replace($repeatPayload,['revision'=>0]),expected:409);
$api('previewOptions',$repeatPayload,expected:403,csrf:false);
$api('publish',['id'=>$repeatPreview['id'],'revision'=>$repeatSaved['revision']],expected:422);
$repeatAfter=$api('record',query:['id'=>$repeatPreview['id']]);
$assert(empty($repeatAfter['form']['published_version_id']) && (int)$repeatAfter['form']['draft_revision']===$repeatSaved['revision'],'Repeated preview activated or changed its draft.');
echo "Native repeated preview: minimum rows, disabled submission, scoped remote options, malformed/missing scopes, revision/CSRF and publication isolation passed.\n";

$rowPayload=$repeatPayload+['operation'=>'add','group'=>$repeatGroup,'instance'=>$previewDom->query('//form')->item(0)->getAttribute('id')];
$api('previewRows',expected:405); $api('previewRows',$rowPayload,expected:403,csrf:false);
$api('previewRows',array_replace($rowPayload,['revision'=>0]),expected:409);
$api('previewRows',array_replace($rowPayload,['instance'=>'foreign']),expected:422);
$api('previewRows',array_replace($rowPayload,['group'=>$parentId]),expected:422);
$addedPreview=$api('previewRows',$rowPayload)['form'];
$addedDom=$dom($addedPreview['html']);
$addedRows=json_decode($addedDom->query('//input[@name="nfs_instances"]')->item(0)->getAttribute('value'),true,512,JSON_THROW_ON_ERROR);
$assert(count($addedRows[$repeatGroup])===3 && array_slice($addedRows[$repeatGroup],0,2)===$declarations[$repeatGroup],'Preview add changed existing row identities.');
$assert($addedDom->query('//form')->item(0)->getAttribute('id')===$rowPayload['instance'] && $addedDom->query('//input[@name="nfs['.$key($rowOne,$parentId).']"]')->item(0)->getAttribute('value')==='ES','Preview add lost form identity or sibling value.');
$assert($addedDom->query('//button[@data-nfs-submit and @disabled]')->length===1 && $addedDom->query('//button[@data-nfs-row-change="add" and @disabled]')->length===1,'Preview add bypassed submit/max bounds.');
$api('previewRows',array_replace($rowPayload,['instances'=>$addedRows]),expected:422);
$removedPreview=$api('previewRows',array_replace($rowPayload,['instances'=>$addedRows,'operation'=>'remove','row'=>$addedRows[$repeatGroup][2]]))['form'];
$removedDom=$dom($removedPreview['html']);
$removedRows=json_decode($removedDom->query('//input[@name="nfs_instances"]')->item(0)->getAttribute('value'),true,512,JSON_THROW_ON_ERROR);
$assert($removedRows[$repeatGroup]===$declarations[$repeatGroup] && $removedDom->query('//button[@data-nfs-row-change="remove" and @disabled]')->length===2,'Preview removal did not preserve siblings/minimum.');
$api('previewRows',array_replace($rowPayload,['operation'=>'remove','row'=>$rowOne]),expected:422);
$assert((int)$api('record',query:['id'=>$repeatPreview['id']])['form']['draft_revision']===$repeatSaved['revision'],'Preview rows wrote to the authoring draft.');
echo "Native preview rows: POST/CSRF/revision, scope/identity checks, add/remove bounds, preserved siblings and unchanged authoring state passed.\n";
