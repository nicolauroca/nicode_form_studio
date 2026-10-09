<?php
declare(strict_types=1);
$layoutForm = $api('create',['name'=>'Responsive fieldset acceptance','alias'=>'responsive-layout-'.bin2hex(random_bytes(5))]);
$layoutDraft = $api('record',query:['id'=>$layoutForm['id']])['draft'];
$layoutDraft['elements'] = []; $layoutDraft['fields'] = [];
$layoutParent = null;
foreach (['section','row','columns','fieldset'] as $i=>$type) {
    $uuid = 'c1252f88-f57e-468c-9453-c0121392400'.$i;
    $layoutDraft['elements'][] = ['uuid'=>$uuid,'type'=>$type,'parent_uuid'=>$layoutParent,'title'=>$type==='fieldset'?'Contact information':''];
    $layoutParent = $uuid;
}
$layoutFields = [];
foreach (['First name','Last name','City'] as $i=>$label) {
    $uuid = 'c1252f88-f57e-468c-9453-c0121392410'.$i; $layoutFields[] = $uuid;
    $layoutDraft['elements'][] = ['uuid'=>$uuid,'type'=>'field','parent_uuid'=>$layoutParent,'width'=>['desktop'=>4,'tablet'=>6,'mobile'=>12]];
    $layoutDraft['fields'][] = ['uuid'=>$uuid,'type'=>'text','name'=>'layout_'.$i,'config'=>['label'=>$label]];
}
$layoutRevision = $api('save',['id'=>$layoutForm['id'],'revision'=>0,'draft'=>$layoutDraft])['revision'];
$api('publish',['id'=>$layoutForm['id'],'revision'=>$layoutRevision]);
$layoutPreview = $api('preview',query:['id'=>$layoutForm['id']]);
$layoutUrl = 'http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id='.$layoutForm['id'];
$layoutPage = $visitorRequest($layoutUrl);
foreach ([$layoutPreview['html'],$layoutPage['body']] as $html) {
    $xpath = $dom($html); $fieldset = $xpath->query('//fieldset[@data-nfs-element="'.$layoutParent.'"]')->item(0);
    $assert($xpath->query('//form[@data-nfs-form]//h3[not(normalize-space())]')->length === 0, 'Untitled layout containers emitted empty headings.');
    $assert($fieldset instanceof DOMElement && $xpath->query('./legend',$fieldset)->item(0)?->textContent === 'Contact information','Semantic fieldset/legend missing.');
    foreach ($layoutFields as $uuid) {
        $field = $xpath->query('./div[@data-nfs-element="'.$uuid.'"]',$fieldset)->item(0);
        $classes = $field instanceof DOMElement ? explode(' ',$field->getAttribute('class')) : [];
        $assert(array_diff(['nfs-desktop-4','nfs-tablet-6','nfs-mobile-12'],$classes) === [],'Conceptual responsive widths missing.');
    }
}
file_put_contents($root.'/build/native-layout-fixture.json',json_encode(['form_id'=>$layoutForm['id'],'url'=>$layoutUrl,'fields'=>$layoutFields],JSON_THROW_ON_ERROR));
echo "Native layout: nested section/row/columns/fieldset, semantic legend and three conceptual widths match preview and public rendering.\n";

$depthForm = $api('create', ['name'=>'Layout depth acceptance', 'alias'=>'layout-depth-'.bin2hex(random_bytes(5))]);
$depthDraft = $api('record', query:['id'=>$depthForm['id']])['draft'];
$depthDraft['elements'] = []; $depthDraft['fields'] = []; $depthParent = null;
for ($i = 0; $i < 64; $i++) {
    $uuid = sprintf('96dc1ffd-314d-4af9-90f0-%012d', $i);
    $depthDraft['elements'][] = ['uuid'=>$uuid,'type'=>'group','parent_uuid'=>$depthParent]; $depthParent = $uuid;
}
$depthRevision = $api('save', ['id'=>$depthForm['id'],'revision'=>0,'draft'=>$depthDraft])['revision'];
$depthPublished = $api('publish', ['id'=>$depthForm['id'],'revision'=>$depthRevision]);
$depthRecord = $api('record', query:['id'=>$depthForm['id']]);
$invalidDepthDraft = $depthDraft;
$invalidDepthDraft['elements'][] = ['uuid'=>'96dc1ffd-314d-4af9-90f0-000000000064','type'=>'group','parent_uuid'=>$depthParent];
$depthRevision = $api('save', ['id'=>$depthForm['id'],'revision'=>$depthRecord['form']['draft_revision'],'draft'=>$invalidDepthDraft])['revision'];
$depthFailure = $api('publish', ['id'=>$depthForm['id'],'revision'=>$depthRevision], expected:422);
$depthErrors = array_values(array_filter($depthFailure['diagnostics'], static fn (array $diagnostic): bool => $diagnostic['code'] === 'layout.depth'));
$assert(count($depthErrors) === 1 && $depthErrors[0]['path'] === '/elements/64/parent_uuid', 'Depth overflow did not identify the first unsupported container.');
$depthRecord = $api('record', query:['id'=>$depthForm['id']]);
$assert((int)$depthRecord['form']['published_version_id'] === $depthPublished['version_id'] && (int)$depthRecord['form']['draft_revision'] === $depthRevision, 'Depth failure changed activation or draft revision.');
$depthPage = $visitorRequest('http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id='.$depthForm['id']);
$assert($depthPage['status'] === 200 && substr_count($depthPage['body'], 'class="nfs-element nfs-group"') === 64, 'Previously published depth boundary no longer renders.');
$depthRevision = $api('save', ['id'=>$depthForm['id'],'revision'=>$depthRevision,'draft'=>$depthDraft])['revision'];
$api('publish', ['id'=>$depthForm['id'],'revision'=>$depthRevision]);
echo "Native layout depth: 64 containers render; container 65 rejects publication with exact path, preserving active version and revision; corrected depth publishes.\n";

$parentRecord = $api('record', query:['id'=>$depthForm['id']]);
$parentRevision = (int)$parentRecord['form']['draft_revision'];
foreach ([[], false, 1.5] as $badParent) {
    $badParentDraft = $depthDraft; $badParentDraft['elements'][0]['parent_uuid'] = $badParent;
    $api('save', ['id'=>$depthForm['id'],'revision'=>$parentRevision,'draft'=>$badParentDraft], expected:422);
    $currentParentRecord = $api('record', query:['id'=>$depthForm['id']]);
    $assert($currentParentRecord['form']['published_version_id'] === $parentRecord['form']['published_version_id'] && (int)$currentParentRecord['form']['draft_revision'] === $parentRevision && $currentParentRecord['draft'] === $parentRecord['draft'], 'Malformed parent rejection mutated the draft or active state.');
    $parentPreview = $api('preview', query:['id'=>$depthForm['id']]);
    $assert(is_string($parentPreview['html']) && substr_count($parentPreview['html'], 'class="nfs-element nfs-group"') === 64, 'Rejected parent damaged the existing preview.');
}
echo "Native malformed layout parents: array, boolean and decimal reject at save with HTTP 422; draft, revision, active version and preview remain intact.\n";

$repeatableDraft = $depthDraft; $repeatableDraft['elements'][0]['type'] = 'repeatable-group';
$repeatableRevision = $api('save', ['id'=>$depthForm['id'],'revision'=>$parentRevision,'draft'=>$repeatableDraft])['revision'];
$repeatableFailure = $api('publish', ['id'=>$depthForm['id'],'revision'=>$repeatableRevision], expected:422);
$repeatableErrors = array_values(array_filter($repeatableFailure['diagnostics'], static fn (array $diagnostic): bool => $diagnostic['code'] === 'layout.repeatable.limits'));
$assert(count($repeatableErrors) === 1 && $repeatableErrors[0]['path'] === '/elements/0/repeat', 'Repeated instances without limits silently published.');
$repeatableRecord = $api('record', query:['id'=>$depthForm['id']]);
$assert($repeatableRecord['form']['published_version_id'] === $parentRecord['form']['published_version_id'] && $repeatableRecord['draft']['elements'][0]['type'] === 'repeatable-group', 'Rejected repetition changed activation or discarded the editable draft node.');
foreach ([['min'=>0,'max'=>0],['min'=>1,'max'=>3],['min'=>4,'max'=>2],['min'=>'1','max'=>3]] as $limits) {
    $repeatableDraft['elements'][0]['repeat']=$limits;
    $repeatableRevision=$api('save',['id'=>$depthForm['id'],'revision'=>$repeatableRevision,'draft'=>$repeatableDraft])['revision'];
    $repeatableRecord=$api('record',query:['id'=>$depthForm['id']]);
    $savedLimits=$repeatableRecord['draft']['elements'][0]['repeat'];
    $assert(count($savedLimits)===2 && $savedLimits['min']===$limits['min'] && $savedLimits['max']===$limits['max'],'Repeated draft limits changed type or value during save/reload.');
    $invalid=!is_int($limits['min']) || $limits['min']>$limits['max'];
    $before=$api('record',query:['id'=>$depthForm['id']]);
    $result=$api('publish',['id'=>$depthForm['id'],'revision'=>$repeatableRevision],expected:$invalid?422:200);
    $record=$api('record',query:['id'=>$depthForm['id']]);
    if ($invalid) {
        $limitErrors=array_values(array_filter($result['diagnostics'],static fn(array $diagnostic):bool=>$diagnostic['code']==='layout.repeatable.limits'));
        $assert(count($limitErrors)===1 && $limitErrors[0]['path']==='/elements/0/repeat','Invalid repeated limits lost their inspector target.');
        $assert($record['form']['published_version_id']===$before['form']['published_version_id'] && (int)$record['form']['draft_revision']===$repeatableRevision,'Rejected limits changed live version or revision.');
    } else {
        $assert((int)$record['form']['published_version_id']===(int)$result['version_id'],'Valid repeated limits failed to activate.');
        $repeatableRevision=(int)$result['revision'];
    }
}
$api('save', ['id'=>$depthForm['id'],'revision'=>$repeatableRevision,'draft'=>$depthDraft]);
echo "Native repetition limits: valid bounds publish; missing or invalid bounds preserve active version and draft identity.\n";
