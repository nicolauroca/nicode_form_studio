<?php
declare(strict_types=1);
require_once $root.'/src/lib_nicode_form_studio/autoload.php';

// The subject always contains a newline from this required textarea; preparation
// rejects it before MailTransport is called. No valid-email submission is made.
$afForm=$api('create',['name'=>'Action failure policies','alias'=>'action-failure-'.bin2hex(random_bytes(6))]);
$afDraft=$api('record',query:['id'=>$afForm['id']])['draft'];
[$afField,$afMail,$afRedirect]=array_map(static fn()=>Nicode\FormStudio\Domain\Uuid::create(),[1,2,3]);
$afDraft['elements']=[['uuid'=>$afField,'type'=>'field']];
$afDraft['fields']=[['uuid'=>$afField,'name'=>'answer','type'=>'textarea','config'=>['label'=>'Answer','required'=>true]]];
$afDraft['post_submit']=['behavior'=>'hide','show_reference'=>true];
$afDraft['actions']=[
    ['uuid'=>$afMail,'type'=>'email_notification','order'=>0,'enabled'=>true,'failure_policy'=>'blocking','config'=>['to'=>['never-deliver@example.test'],'subject'=>'{{field.'.$afField.'.value}}','body_text'=>'Synthetic fixture']],
    ['uuid'=>$afRedirect,'type'=>'redirect','order'=>1,'enabled'=>true,'failure_policy'=>'non_blocking','config'=>['url'=>'/index.php?after_failure=1']],
];
$afRevision=0;
$afRuns=$prefillDb->prepare('SELECT a.id,a.action_uuid,a.attempt,a.state,a.result_code FROM j6_nicode_form_studio_action_runs a JOIN j6_nicode_form_studio_submissions s ON s.id=a.submission_id WHERE s.form_id=? AND s.uuid=? ORDER BY a.id');
$afStored=$prefillDb->prepare('SELECT action_status FROM j6_nicode_form_studio_submissions WHERE form_id=? AND uuid=?');
try {
    foreach(['blocking','non_blocking'] as $policy) {
        $afDraft['actions'][0]['failure_policy']=$policy;
        $afRevision=$api('save',['id'=>$afForm['id'],'revision'=>$afRevision,'draft'=>$afDraft])['revision'];
        $afPublished=$api('publish',['id'=>$afForm['id'],'revision'=>$afRevision]); $afRevision=$afPublished['revision'];
        $page=$visitorRequest('http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id='.$afForm['id']);
        $xp=$dom($page['body']); $node=$xp->query('//form[@data-nfs-form]')->item(0);
        $assert($page['status']===200 && $node instanceof DOMElement,'Failure policy fixture did not render.');
        $post=['format'=>'json','nfs'=>[$afField=>"Synthetic first line\nSynthetic second line"]];
        foreach($xp->query('.//input[@type="hidden"]',$node) as $input) { $post[$input->getAttribute('name')]=$input->getAttribute('value'); }
        $destination='http://127.0.0.1:13371'.$node->getAttribute('action');
        $response=$visitorRequest($destination,$post); $result=json_decode($response['body'],true,512,JSON_THROW_ON_ERROR); $blocking=$policy==='blocking';
        $assert($response['status']===200 && $result['accepted'] && $result['processed']===!$blocking && $result['category']===($blocking?'action_blocking_failure':'action_partial_failure'),'Visitor failure category lost received/processed distinction.');
        $assert($result['behavior']===($blocking?'keep':'hide') && ($result['redirect']??null)===($blocking?null:'/index.php?after_failure=1'),'Failure policy applied incorrect behavior or continuation.');
        $afStored->execute([$afForm['id'],$result['reference']]);
        $assert($afStored->fetchColumn()===($blocking?'blocking_failure':'partial_failure'),'Persisted failure status differs from public outcome.');
        $afRuns->execute([$afForm['id'],$result['reference']]); $rows=$afRuns->fetchAll(PDO::FETCH_ASSOC);
        $assert(count($rows)===($blocking?1:2) && $rows[0]['action_uuid']===$afMail && $rows[0]['state']==='failed' && $rows[0]['result_code']==='mail_preparation_failed','Preparation failure did not stop before mail transport or was classified unknown.');
        if(!$blocking) { $assert($rows[1]['action_uuid']===$afRedirect && $rows[1]['state']==='succeeded','Nonblocking failure stopped the next action.'); }
        $repeat=$visitorRequest($destination,$post); $again=json_decode($repeat['body'],true,512,JSON_THROW_ON_ERROR);
        $assert($repeat['status']===200 && $again['replayed'] && $again['reference']===$result['reference'] && $again['category']===$result['category'],'Failure replay changed receipt/category.');
        $afRuns->execute([$afForm['id'],$result['reference']]); $assert($afRuns->fetchAll(PDO::FETCH_ASSOC)===$rows,'Failure replay implicitly retried actions.');
    }
    file_put_contents($root.'/build/native-action-failures-results.json',json_encode(['passed'=>true,'timestamp'=>gmdate(DATE_ATOM),'form_id'=>$afForm['id'],'blocking_and_nonblocking'=>true,'preparation_only'=>true,'replay_fenced'=>true],JSON_THROW_ON_ERROR));
} finally {
    $current=$api('record',query:['id'=>$afForm['id']]);
    $api('deactivate',['id'=>$afForm['id'],'revision'=>(int)$current['form']['draft_revision'],'state'=>'unpublished']);
}
echo "Native failure policies: received/processed distinction, blocking stop, nonblocking continuation, safe preparation failure and replay fencing passed.\n";
