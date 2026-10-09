<?php
declare(strict_types=1);
require_once $root.'/src/lib_nicode_form_studio/autoload.php';

$caForm=$api('create',['name'=>'Conditional actions acceptance','alias'=>'conditional-actions-'.bin2hex(random_bytes(6))]);
$caDraft=$api('record',query:['id'=>$caForm['id']])['draft'];
$caField=Nicode\FormStudio\Domain\Uuid::create();
$caDraft['elements']=[['uuid'=>$caField,'type'=>'field']];
$caDraft['fields']=[['uuid'=>$caField,'name'=>'route','type'=>'text','config'=>['label'=>'Route','required'=>true]]];
$caDraft['actions']=[]; $caIds=[];
foreach(['first','A','B'] as $order=>$route) {
    $caIds[$route]=Nicode\FormStudio\Domain\Uuid::create();
    $when=$route==='first'?['group'=>'OR','children'=>[['field'=>$caField,'operator'=>'equals','value'=>'A'],['field'=>$caField,'operator'=>'equals','value'=>'B']]]:['field'=>$caField,'operator'=>'equals','value'=>$route];
    $caDraft['actions'][]=['uuid'=>$caIds[$route],'type'=>'redirect','enabled'=>true,'order'=>$order,'condition'=>$when,'failure_policy'=>'non_blocking','config'=>['url'=>'/index.php?conditional_route='.$route]];
}
$caRevision=$api('save',['id'=>$caForm['id'],'revision'=>0,'draft'=>$caDraft])['revision'];
$api('publish',['id'=>$caForm['id'],'revision'=>$caRevision]);
$caUrl='http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id='.$caForm['id'];
$caRuns=$prefillDb->prepare('SELECT a.id,a.action_uuid,a.attempt,a.state,a.result_code FROM j6_nicode_form_studio_action_runs a JOIN j6_nicode_form_studio_submissions s ON s.id=a.submission_id WHERE s.form_id=? AND s.uuid=? ORDER BY a.id');
try {
    foreach(['A','B','neither'] as $route) {
        $page=$visitorRequest($caUrl); $xp=$dom($page['body']); $node=$xp->query('//form[@data-nfs-form]')->item(0);
        $assert($page['status']===200 && $node instanceof DOMElement,'Conditional actions fixture failed to render.');
        $post=['format'=>'json','nfs'=>[$caField=>'  '.$route.'  ']];
        foreach($xp->query('.//input[@type="hidden"]',$node) as $input) { $post[$input->getAttribute('name')]=$input->getAttribute('value'); }
        $destination='http://127.0.0.1:13371'.$node->getAttribute('action');
        $bad=$post; $bad['nfs'][$caField]='';
        $assert($visitorRequest($destination,$bad)['status']===422,'Actions accepted invalid required answer.');
        $response=$visitorRequest($destination,$post); $result=json_decode($response['body'],true,512,JSON_THROW_ON_ERROR);
        $assert($response['status']===200 && $result['accepted'] && $result['category']==='success','Conditional actions submission failed.');
        $assert(($result['redirect']??null)===($route==='neither'?null:'/index.php?conditional_route='.$route),'Conditional action order or normalized predicate selected the wrong redirect.');
        $caRuns->execute([$caForm['id'],$result['reference']]); $rows=$caRuns->fetchAll(PDO::FETCH_ASSOC);
        $assert(count($rows)===3 && array_column($rows,'action_uuid')===array_values($caIds),'Conditional actions lost execution order or run records.');
        foreach($rows as $position=>$run) {
            $key=array_keys($caIds)[$position]; $matches=$route!=='neither' && ($key==='first' || $key===$route);
            $assert($run['state']===($matches?'succeeded':'skipped') && $run['result_code']===($matches?'navigation_selected':'condition_false') && (int)$run['attempt']===1,'Condition state/result/attempt differs from normalized response.');
        }
        $replayed=$visitorRequest($destination,$post); $again=json_decode($replayed['body'],true,512,JSON_THROW_ON_ERROR);
        $assert($replayed['status']===200 && $again['replayed'] && $again['reference']===$result['reference'] && ($again['redirect']??null)===($result['redirect']??null),'Conditional replay changed receipt or navigation.');
        $caRuns->execute([$caForm['id'],$result['reference']]);
        $assert($caRuns->fetchAll(PDO::FETCH_ASSOC)===$rows,'Conditional replay repeated persisted action attempts.');
    }
    $caCount=$prefillDb->prepare('SELECT COUNT(*) FROM j6_nicode_form_studio_submissions WHERE form_id=?'); $caCount->execute([$caForm['id']]);
    $assert((int)$caCount->fetchColumn()===3,'Invalid submissions or replay created additional responses.');
    file_put_contents($root.'/build/native-action-conditions-results.json',json_encode(['passed'=>true,'timestamp'=>gmdate(DATE_ATOM),'form_id'=>$caForm['id'],'routes'=>3,'ordered_actions'=>3,'normalized_conditions'=>true,'replay_fenced'=>true],JSON_THROW_ON_ERROR));
} finally {
    $current=$api('record',query:['id'=>$caForm['id']]);
    $api('deactivate',['id'=>$caForm['id'],'revision'=>(int)$current['form']['draft_revision'],'state'=>'unpublished']);
}
echo "Native conditional actions: normalized predicates, ordered navigation, succeeded/skipped runs and exact replay fencing passed.\n";
