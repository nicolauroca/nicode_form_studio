<?php
declare(strict_types=1);

// Runs only inside the exact-host/database guarded suite and its native visitor session.
require_once $root.'/src/lib_nicode_form_studio/autoload.php';
$rhForm=$api('create',['name'=>'Internal repeated HTTP fixture','alias'=>'repeated-http-'.bin2hex(random_bytes(6))]);
$rhData=$api('record',query:['id'=>$rhForm['id']])['draft'];
[$rhGroup,$rhField]=array_map(static fn()=>Nicode\FormStudio\Domain\Uuid::create(),[1,2]);
$rhData['elements']=[['uuid'=>$rhGroup,'type'=>'repeatable-group','repeat'=>['min'=>2,'max'=>3]],['uuid'=>$rhField,'type'=>'field','parent_uuid'=>$rhGroup]];
$rhData['fields']=[['uuid'=>$rhField,'name'=>'answer','type'=>'text','index'=>true,'config'=>['label'=>'Repeated answer','required'=>true]]];
$rhData['actions']=[]; $rhData['security']['captcha']=['mode'=>'inherit'];
$rhData['post_submit']=['behavior'=>'keep'];
$rhSaved=$api('save',['id'=>$rhForm['id'],'revision'=>0,'draft'=>$rhData]);
$rhRecord=$api('record',query:['id'=>$rhForm['id']]);
$rhData=$rhRecord['draft'];
$rhPreview=$api('preview',query:['id'=>$rhForm['id']]);
$rhPreviewXp=$dom($rhPreview['html']);
$rhPreviewRows=json_decode($rhPreviewXp->query('//input[@name="nfs_instances"]')->item(0)->getAttribute('value'),true,512,JSON_THROW_ON_ERROR);
$assert(count($rhPreviewRows[$rhGroup])===2,'Saved repeated draft did not compile and render its minimum rows.');
$assert($rhPreviewXp->query('//*[@data-nfs-input]')->length===2,'Saved repeated draft preview lost addressed fields.');
$rhPublished=$api('publish',['id'=>$rhForm['id'],'revision'=>$rhSaved['revision']]);
$rhVersion=(int)$rhPublished['version_id'];
$rhAfterPublished=$api('record',query:['id'=>$rhForm['id']]);
$assert((int)$rhAfterPublished['form']['published_version_id']===$rhVersion && $rhAfterPublished['draft']===$rhData,'Repeated publication changed draft or failed to activate its compiled version.');
try {
    $rhUrl='http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id='.$rhForm['id'];
    $page=$visitorRequest($rhUrl); $xp=$dom($page['body']); $node=$xp->query('//form[@data-nfs-form]')->item(0);
    $assert($page['status']===200 && $node instanceof DOMElement,'Native repeated form did not render.');
    $rhPost=[];
    foreach($xp->query('.//input[@type="hidden"]',$node) as $input) { $rhPost[$input->getAttribute('name')]=$input->getAttribute('value'); }
    $rhRows=json_decode($rhPost['nfs_instances'],true,512,JSON_THROW_ON_ERROR);
    $assert(count($rhRows[$rhGroup])===2,'Native initial rows differ from minimum.');
    [$rhOne,$rhTwo]=$rhRows[$rhGroup]; $rhKey1=$rhGroup.'/'.$rhOne.'/'.$rhField; $rhKey2=$rhGroup.'/'.$rhTwo.'/'.$rhField;
    $rhDestination='http://127.0.0.1:13371'.$node->getAttribute('action');
    $rhPost['nfs']=[$rhKey1=>'First preserved answer',$rhKey2=>''];
    $rhRowsUrl='http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&task=form.rows&format=json';
    $assert($visitorRequest($rhRowsUrl)['status']===405,'Row endpoint accepted GET.');
    $assert($visitorRequest($rhRowsUrl,['form_id'=>$rhForm['id'],'version_id'=>$rhVersion])['status']===403,'Row endpoint accepted missing CSRF.');
    $rowPost=$rhPost+['row_operation'=>'add','row_group'=>$rhGroup];
    $rowResponse=$visitorRequest($rhRowsUrl,$rowPost); $rowResult=json_decode($rowResponse['body'],true,512,JSON_THROW_ON_ERROR);
    $assert($rowResponse['status']===200 && ($rowResult['ok']??false),'Native row addition failed.');
    $rowXp=$dom($rowResult['form']['html']); $rowNode=$rowXp->query('//form[@data-nfs-form]')->item(0); $rowHidden=[];
    foreach($rowXp->query('.//input[@type="hidden"]',$rowNode) as $input) { $rowHidden[$input->getAttribute('name')]=$input->getAttribute('value'); }
    $addedRows=json_decode($rowHidden['nfs_instances'],true,512,JSON_THROW_ON_ERROR);
    $assert(count($addedRows[$rhGroup])===3 && array_slice($addedRows[$rhGroup],0,2)===$rhRows[$rhGroup] && $rowHidden['attempt']===$rhPost['attempt'],'Native row addition replaced existing identity.');
    $assert($rowXp->query('.//input[@data-nfs-input="'.$rhKey1.'"]',$rowNode)->item(0)?->getAttribute('value')==='First preserved answer','Native row addition lost existing answer.');
    $addedPost=$rowHidden+['nfs'=>$rhPost['nfs'],'row_operation'=>'add','row_group'=>$rhGroup];
    $assert($visitorRequest($rhRowsUrl,$addedPost)['status']===422,'Native row endpoint exceeded maximum.');
    $addedPost['row_operation']='remove'; $addedPost['row_id']=$addedRows[$rhGroup][2];
    $rowResponse=$visitorRequest($rhRowsUrl,$addedPost); $rowResult=json_decode($rowResponse['body'],true,512,JSON_THROW_ON_ERROR);
    $assert($rowResponse['status']===200 && ($rowResult['ok']??false),'Native row removal failed.');
    $rowXp=$dom($rowResult['form']['html']);
    $assert(json_decode($rowXp->query('//input[@name="nfs_instances"]')->item(0)->getAttribute('value'),true)===$rhRows,'Native removal changed sibling declarations.');
    $minimum=$rhPost+['row_operation'=>'remove','row_group'=>$rhGroup,'row_id'=>$rhOne];
    $assert($visitorRequest($rhRowsUrl,$minimum)['status']===422,'Native removal crossed minimum.');
    $count=$prefillDb->prepare('SELECT COUNT(*) FROM j6_nicode_form_studio_submissions WHERE form_id=?'); $count->execute([$rhForm['id']]);
    $assert((int)$count->fetchColumn()===0,'Row editing created a submission.');
    $nativeAdd=$xp->query('.//button[@data-nfs-row-change="add"]',$node)->item(0);
    $assert($nativeAdd instanceof DOMElement && $nativeAdd->hasAttribute('formnovalidate') && !$nativeAdd->hasAttribute('disabled'),'Native add control cannot edit an incomplete form.');
    $assert($xp->query('.//button[@data-nfs-row-change="remove"][@disabled]',$node)->length===2,'Native minimum removal controls remain enabled.');
    $htmlPost=$rhPost+['row_change'=>$nativeAdd->getAttribute('value')];
    $htmlAdded=$visitorRequest($rhDestination,$htmlPost); $htmlXp=$dom($htmlAdded['body']); $htmlNode=$htmlXp->query('//form[@data-nfs-form]')->item(0);
    $assert($htmlAdded['status']===200 && $htmlNode instanceof DOMElement,'Native button did not route to HTML row editing.');
    $htmlHidden=[]; foreach($htmlXp->query('.//input[@type="hidden"]',$htmlNode) as $input) { $htmlHidden[$input->getAttribute('name')]=$input->getAttribute('value'); }
    $htmlRows=json_decode($htmlHidden['nfs_instances'],true,512,JSON_THROW_ON_ERROR);
    $assert(count($htmlRows[$rhGroup])===3 && $htmlHidden['attempt']===$rhPost['attempt'],'Native HTML addition changed attempt or failed to add.');
    $assert($htmlXp->query('.//button[@data-nfs-row-change="add"][@disabled]',$htmlNode)->length===1,'Native maximum add control remains enabled.');
    $overLimit=$htmlHidden+['nfs'=>$rhPost['nfs'],'row_change'=>$nativeAdd->getAttribute('value')];
    $rejectedChange=$visitorRequest($rhDestination,$overLimit); $rejectedXp=$dom($rejectedChange['body']);
    $assert($rejectedChange['status']===422 && $rejectedXp->query('//input[@data-nfs-input="'.$rhKey1.'"]')->item(0)?->getAttribute('value')==='First preserved answer','Rejected native row edit lost existing answers.');
    $assert(json_decode($rejectedXp->query('//input[@name="nfs_instances"]')->item(0)->getAttribute('value'),true)===$htmlRows,'Rejected row edit changed declarations.');
    $nativeRemove=$htmlXp->query('.//button[@data-nfs-row-change="remove"]',$htmlNode)->item(2);
    $removeHtml=$visitorRequest($rhDestination,$htmlHidden+['nfs'=>$rhPost['nfs'],'row_change'=>$nativeRemove->getAttribute('value')]);
    $assert($removeHtml['status']===200 && json_decode($dom($removeHtml['body'])->query('//input[@name="nfs_instances"]')->item(0)->getAttribute('value'),true)===$rhRows,'Native HTML removal changed siblings.');
    foreach(['{broken',json_encode(['operation'=>'add','group'=>[$rhGroup]]),json_encode(['operation'=>'add','group'=>$rhGroup,'extra'=>true])] as $badChange) {
        $assert($visitorRequest($rhDestination,$rhPost+['row_change'=>$badChange,'format'=>'json'])['status']===422,'Malformed button operation accepted.');
    }
    $assert($visitorRequest($rhDestination,$htmlPost+['row_operation'=>'add','format'=>'json'])['status']===422,'Mixed row operation envelopes accepted.');
    $retry=$visitorRequest($rhDestination,$rhPost); $retryXp=$dom($retry['body']); $retryNode=$retryXp->query('//form[@data-nfs-form]')->item(0);
    $assert($retry['status']===422 && $retryNode instanceof DOMElement,'Native repeated HTML validation did not redisplay.');
    $assert($retryXp->query('.//input[@data-nfs-input="'.$rhKey1.'"]',$retryNode)->item(0)?->getAttribute('value')==='First preserved answer','HTML retry lost a sibling answer.');
    $assert($retryXp->query('.//*[@data-nfs-error-target="'.$rhKey2.'"]',$retryNode)->length===1,'HTML retry error lost its field address.');
    $retryHidden=[]; foreach($retryXp->query('.//input[@type="hidden"]',$retryNode) as $input) { $retryHidden[$input->getAttribute('name')]=$input->getAttribute('value'); }
    $assert($retryHidden['nfs_instances']===$rhPost['nfs_instances'] && $retryHidden['attempt']===$rhPost['attempt'],'HTML retry replaced rows or attempt.');
    $rhOptionsUrl='http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&task=form.options&format=json';
    $options=$visitorRequest($rhOptionsUrl,$rhPost);
    $assert($options['status']===200 && json_decode($options['body'],true)['ok']===true,'Repeated options controller did not route envelope.');
    $missing=$rhPost; unset($missing['nfs_instances']);
    $assert($visitorRequest($rhOptionsUrl,$missing)['status']===422,'Options accepted missing row envelope.');
    $missing['format']='json'; $assert($visitorRequest($rhDestination,$missing)['status']===422,'Submit accepted missing row envelope.');
    $rhPost['format']='json'; $rhPost['nfs'][$rhKey2]='Second answer';
    $accepted=$visitorRequest($rhDestination,$rhPost); $answer=json_decode($accepted['body'],true,512,JSON_THROW_ON_ERROR);
    $assert($accepted['status']===200 && ($answer['accepted']??false),'Native repeated submission failed: '.json_encode($answer));
    $rhRead=$prefillDb->prepare('SELECT canonical_payload FROM j6_nicode_form_studio_submissions WHERE form_id=? AND uuid=?');
    $rhRead->execute([$rhForm['id'],$answer['reference']]); $payload=json_decode($rhRead->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
    $assert($payload['instances']===$rhRows && count($payload['values'])===count($rhPost['nfs']),'Native submission changed row ownership or answer count.');
    foreach($rhPost['nfs'] as $key=>$value) { $assert(($payload['values'][$key]??null)===$value,'Native submission changed an addressed answer.'); }
    $replay=$visitorRequest($rhDestination,$rhPost); $again=json_decode($replay['body'],true,512,JSON_THROW_ON_ERROR);
    $assert($replay['status']===200 && ($again['replayed']??false) && $again['reference']===$answer['reference'],'Native repeated replay lost identity.');
    $rhColumns=$submissionApi('search',['query'=>json_encode(['filters'=>['form_id'=>$rhForm['id']],'columns'=>[$rhField]])]);
    $rhCell=$rhColumns['rows'][0]['cells'][$rhField];
    $assert($rhCell['present'] && !$rhCell['masked'] && array_column($rhCell['value'],'value')===array_values($rhPost['nfs']),'Native repeated answer columns lost values or row order.');
    $assert(array_column($rhCell['value'],'instance_path')===[$rhGroup.'/'.$rhOne,$rhGroup.'/'.$rhTwo],'Native repeated answer columns lost row identity.');
    $assert($rhColumns['filter_fields'][0]['same_instance']===true,'Repeated filter metadata did not advertise row correlation.');
    $rhIndependent=[['field'=>$rhField,'operator'=>'equals','value'=>'First preserved answer'],['field'=>$rhField,'operator'=>'equals','value'=>'Second answer']];
    $rhQuery=['filters'=>['form_id'=>$rhForm['id']],'fields'=>$rhIndependent,'columns'=>[$rhField]];
    $assert(count($submissionApi('search',['query'=>json_encode($rhQuery)])['rows'])===1,'Independent repeated filters did not match separate rows.');
    foreach($rhQuery['fields'] as &$rhFilter) { $rhFilter['same_instance']='contact'; } unset($rhFilter);
    $assert($submissionApi('search',['query'=>json_encode($rhQuery)])['rows']===[],'Same-row filters incorrectly matched different rows.');
    $rhQuery['fields'][1]['operator']='not_equals';
    $assert(count($submissionApi('search',['query'=>json_encode($rhQuery)])['rows'])===1,'Same-row negative predicate did not use its positive anchor.');
    $rhPreset=$submissionApi('saveView',payload:['name'=>'Repeated row query','query'=>$rhQuery]);
    try {
        $rhLoadedQuery=$submissionApi('savedView',['id'=>$rhPreset['id']])['query'];
        $assert(Nicode\FormStudio\Domain\CanonicalJson::encode($rhLoadedQuery)===Nicode\FormStudio\Domain\CanonicalJson::encode($rhQuery),'Saved repeated query lost correlation: '.json_encode([$rhLoadedQuery,$rhQuery]));
        $rhPresetPage=$request($base.'?'.http_build_query(['option'=>'com_nicode_form_studio','view'=>'submissions','preset'=>$rhPreset['id']]));
        $rhPresetXp=$dom($rhPresetPage['body']);
        $assert($rhPresetPage['status']===200 && Nicode\FormStudio\Domain\CanonicalJson::encode(json_decode($rhPresetXp->query('//input[@name="field_filters"]')->item(0)->getAttribute('value'),true))===Nicode\FormStudio\Domain\CanonicalJson::encode($rhQuery['fields']),'Native query editor did not receive saved correlations.');
    } finally { $submissionApi('removeView',payload:['id'=>$rhPreset['id']]); }
    $rhListUrl=$base.'?'.http_build_query(['option'=>'com_nicode_form_studio','view'=>'submissions','form_id'=>$rhForm['id'],'columns'=>[$rhField]]);
    $rhList=$request($rhListUrl); $rhListXp=$dom($rhList['body']);
    $assert($rhList['status']===200 && $rhListXp->query('//td[@title="Repeated answer"]/pre')->length===1,'Native repeated column view did not render.');
    $assert(json_decode($rhListXp->query('//td[@title="Repeated answer"]/pre')->item(0)->textContent,true,512,JSON_THROW_ON_ERROR)===$rhCell['value'],'Native repeated column HTML changed the authorized projection.');
    foreach([[],[$rhField]] as $offset=>$preserve) {
        $rhData['post_submit']=['behavior'=>'reset','preserve'=>$preserve];
        $rhData['fields'][0]['config']['default']='Reset default';
        $current=$api('record',query:['id'=>$rhForm['id']]);
        $saved=$api('save',['id'=>$rhForm['id'],'revision'=>(int)$current['form']['draft_revision'],'draft'=>$rhData]);
        $published=$api('publish',['id'=>$rhForm['id'],'revision'=>$saved['revision']]);
        $rhVersion=(int)$published['version_id'];
        $page=$visitorRequest($rhUrl); $xp=$dom($page['body']); $node=$xp->query('//form[@data-nfs-form]')->item(0); $post=[];
        foreach($xp->query('.//input[@type="hidden"]',$node) as $input) { $post[$input->getAttribute('name')]=$input->getAttribute('value'); }
        $rows=json_decode($post['nfs_instances'],true,512,JSON_THROW_ON_ERROR); $post['nfs']=[];
        foreach($rows[$rhGroup] as $index=>$row) { $post['nfs'][$rhGroup.'/'.$row.'/'.$rhField]='Preserved '.$index; }
        $reset=$visitorRequest($rhDestination,$post); $xp=$dom($reset['body']); $node=$xp->query('//form[@data-nfs-form]')->item(0);
        $assert($reset['status']===200 && $node instanceof DOMElement,'Native repeated reset did not redisplay.');
        foreach($post['nfs'] as $key=>$value) {
            $assert($xp->query('.//input[@data-nfs-input="'.$key.'"]',$node)->item(0)?->getAttribute('value')===($preserve===[]?'Reset default':$value),'Native reset lost defaults or crossed preserved rows.');
        }
        $hidden=[]; foreach($xp->query('.//input[@type="hidden"]',$node) as $input) { $hidden[$input->getAttribute('name')]=$input->getAttribute('value'); }
        $assert($hidden['nfs_instances']===$post['nfs_instances'] && $hidden['attempt']!==$post['attempt'],'Native reset changed rows or failed to rotate attempt.');
    }
    // Save a sensitive historical snapshot, then restore the public schema as current.
    $rhPrivate=$rhData; $rhPrivate['fields'][0]['sensitive']=true;
    $rhPrivate['fields'][0]['index']=false;
    $rhPrivate['fields'][0]['config']['label']='Private historical repeated title';
    $rhPrivate['post_submit']=['behavior'=>'keep'];
    $current=$api('record',query:['id'=>$rhForm['id']]);
    $saved=$api('save',['id'=>$rhForm['id'],'revision'=>(int)$current['form']['draft_revision'],'draft'=>$rhPrivate]);
    $privatePublished=$api('publish',['id'=>$rhForm['id'],'revision'=>$saved['revision']]);
    $rhPrivateVersion=(int)$privatePublished['version_id'];
    $rhPrivatePage=$visitorRequest($rhUrl); $rhPrivateXp=$dom($rhPrivatePage['body']); $rhPrivateNode=$rhPrivateXp->query('//form[@data-nfs-form]')->item(0); $rhPrivatePost=[];
    foreach($rhPrivateXp->query('.//input[@type="hidden"]',$rhPrivateNode) as $input) { $rhPrivatePost[$input->getAttribute('name')]=$input->getAttribute('value'); }
    $rhPrivateRows=json_decode($rhPrivatePost['nfs_instances'],true,512,JSON_THROW_ON_ERROR);
    $rhPrivatePost['nfs']=[]; $rhPrivatePost['format']='json';
    foreach($rhPrivateRows[$rhGroup] as $row) { $rhPrivatePost['nfs'][$rhGroup.'/'.$row.'/'.$rhField]='Private repeated sentinel'; }
    $rhPrivateAccepted=$visitorRequest($rhDestination,$rhPrivatePost); $rhPrivateResult=json_decode($rhPrivateAccepted['body'],true,512,JSON_THROW_ON_ERROR);
    $assert($rhPrivateAccepted['status']===200 && ($rhPrivateResult['accepted']??false),'Native sensitive repeated fixture did not submit.');
    $restored=$api('restore',['id'=>$rhForm['id'],'revision'=>$privatePublished['revision'],'version_id'=>$rhVersion]);
    $current=$api('record',query:['id'=>$rhForm['id']]);
    $restoredPublished=$api('publish',['id'=>$rhForm['id'],'revision'=>(int)$current['form']['draft_revision']]);
    $rhVersion=(int)$restoredPublished['version_id'];
    $rhHistorical=$submissionApi('search',['query'=>json_encode(['filters'=>['form_id'=>$rhForm['id']],'columns'=>[$rhField]])]);
    $rhHistoricalRows=array_column($rhHistorical['rows'],null,'uuid'); $rhHidden=$rhHistoricalRows[$rhPrivateResult['reference']]['cells'][$rhField];
    $assert($rhHidden['masked'] && !$rhHidden['present'] && $rhHidden['value']===null && $rhHidden['option_label']===null && $rhHidden['label']==='Repeated answer','Native repeated historical column disclosed sensitive data.');
    $rhHistoricalList=$request($rhListUrl);
    $assert($rhHistoricalList['status']===200 && !str_contains($rhHistoricalList['body'],'Private repeated sentinel') && !str_contains($rhHistoricalList['body'],'Private historical repeated title'),'Native repeated historical column HTML disclosed private data.');
    file_put_contents($root.'/build/native-repeated-public-results.json',json_encode(['passed'=>true,'timestamp'=>gmdate(DATE_ATOM),'form_id'=>$rhForm['id'],'version_id'=>$rhVersion,'saved_draft_preview'=>true,'native_publication'=>true,'native_reset_publications'=>true,'administrative_columns'=>true,'historical_column_masking'=>true],JSON_THROW_ON_ERROR));
} finally {
    $prefillDb->prepare("UPDATE j6_nicode_form_studio_forms SET state='unpublished' WHERE id=?")->execute([$rhForm['id']]);
}
echo "Native repeated public HTTP: normal publication, guarded row editing, retry/options, persistence/replay/reset, administrative API/HTML columns and historical sensitive column masking passed.\n";
