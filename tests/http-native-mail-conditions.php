<?php
declare(strict_types=1);

(static function()use($root,$forms,$repository,$db,$admin,$request,$captures,&$created):void {
    $form=$forms->create('Native attachment acceptance','native-mail-conditions-'.bin2hex(random_bytes(5)),(int)$admin->id); $created[]=$form;
    $draft=$forms->edit($form,(int)$admin->id)['draft'];
    [$route,$email]=array_map(static fn()=>Nicode\FormStudio\Domain\Uuid::create(),[1,2]);
    $draft['elements']=[['uuid'=>$route,'type'=>'field'],['uuid'=>$email,'type'=>'field']];
    $draft['fields']=[['uuid'=>$route,'name'=>'route','type'=>'text','config'=>['label'=>'Route','required'=>true]],['uuid'=>$email,'name'=>'email','type'=>'email','config'=>['label'=>'Email','required'=>true]]];
    $draft['security']['captcha']=['mode'=>'none']; $draft['security']['minimum_seconds']=0;
    $ids=[]; $actions=[];
    foreach(['common','visitor','team'] as $order=>$name) {
        $ids[$name]=Nicode\FormStudio\Domain\Uuid::create();
        $condition=$name==='common'?['group'=>'OR','children'=>[['field'=>$route,'operator'=>'equals','value'=>'A'],['field'=>$route,'operator'=>'equals','value'=>'B']]]:['group'=>'AND','children'=>[['field'=>$route,'operator'=>'equals','value'=>$name==='visitor'?'A':'B'],['field'=>$email,'operator'=>'not_empty']]];
        $actions[]=['uuid'=>$ids[$name],'type'=>$name==='visitor'?'email_autoresponse':'email_notification','enabled'=>true,'order'=>$order*10,'failure_policy'=>'non_blocking','condition'=>$condition,'config'=>['to'=>[$name.'@example.test'],'email_field'=>$email,'subject'=>$name.' {{submission.reference}}','body_text'=>'Route {{field.'.$route.'.value}}']];
    }
    $draft['actions']=array_reverse($actions); // Execution order must not follow storage order.
    $revision=$forms->save($form,0,$draft,(int)$admin->id); $forms->publish($form,$revision,(int)$admin->id);
    $readRuns=static fn(string $reference)=>$db->rows('SELECT a.id,a.action_uuid,a.state,a.result_code,a.attempt FROM '.$db->table('action_runs').' a JOIN '.$db->table('submissions').' s ON s.id=a.submission_id WHERE s.form_id=:form AND s.uuid=:uuid ORDER BY a.id',[':form'=>$form,':uuid'=>$reference]);
    foreach(['A','B','neither'] as $choice) {
        [$status,$html]=$request('/index.php?option=com_nicode_form_studio&view=form&id='.$form);
        $document=new DOMDocument(); $prior=libxml_use_internal_errors(true); $document->loadHTML($html); libxml_clear_errors(); libxml_use_internal_errors($prior); $xp=new DOMXPath($document); $node=$xp->query('//form[@data-nfs-form]')->item(0);
        if($status!==200 || !$node instanceof DOMElement) { throw new RuntimeException('Conditional mail fixture did not render.'); }
        $post=['format'=>'json','nfs['.$route.']'=>'  '.$choice.'  ','nfs['.$email.']'=>'visitor@example.test'];
        foreach($xp->query('.//input[@type="hidden"]',$node) as $input) { $post[$input->getAttribute('name')]=$input->getAttribute('value'); }
        $destination=$node->getAttribute('action'); if(str_starts_with($destination,'http')) { $destination=parse_url($destination,PHP_URL_PATH).'?'.parse_url($destination,PHP_URL_QUERY); }
        $before=count($captures()); $invalid=$post; $invalid['nfs['.$route.']']='';
        [$status,$body]=$request($destination,$invalid);
        if($status!==422 || count($captures())!==$before) { throw new RuntimeException('Invalid answer executed conditional mail.'); }
        [$status,$body]=$request($destination,$post); $result=json_decode($body,true,flags:JSON_THROW_ON_ERROR);
        if($status!==200 || !($result['processed']??false) || ($result['category']??null)!=='success') { throw new RuntimeException('Conditional mail submission failed.'); }
        $expected=$choice==='neither'?[]:['common',$choice==='A'?'visitor':'team'];
        $messages=array_map(static fn($line)=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),array_slice($captures(),$before));
        if(count($messages)!==count($expected)) { throw new RuntimeException('Conditional mail count mismatch.'); }
        foreach($messages as $index=>$message) {
            $name=$expected[$index];
            if($message['subject']!==$name.' '.$result['reference'] || $message['to']!==[[$name.'@example.test','']] || $message['html']!=='Route '.$choice) { throw new RuntimeException('Conditional mail order, recipient or normalized value mismatch.'); }
        }
        $runs=$readRuns($result['reference']);
        if(count($runs)!==3 || array_column($runs,'action_uuid')!==array_values($ids)) { throw new RuntimeException('Conditional mail ActionRun order mismatch.'); }
        foreach(array_keys($ids) as $index=>$name) {
            $sent=in_array($name,$expected,true);
            if($runs[$index]['state']!==($sent?'succeeded':'skipped') || $runs[$index]['result_code']!==($sent?'mail_sent':'condition_false') || (int)$runs[$index]['attempt']!==1) { throw new RuntimeException('Conditional mail run state mismatch.'); }
        }
        [$status,$body]=$request($destination,$post); $replay=json_decode($body,true,flags:JSON_THROW_ON_ERROR);
        if(!($replay['replayed']??false) || $replay['reference']!==$result['reference'] || count($captures())!==$before+count($expected) || $readRuns($result['reference'])!==$runs) { throw new RuntimeException('Conditional mail replay changed effects or runs.'); }
    }
    file_put_contents($root.'/build/native-mail-condition-results.json',json_encode(['passed'=>true,'form'=>$form,'timestamp'=>gmdate(DATE_ATOM),'choices'=>['A','B','neither'],'normalized_conditions'=>true,'ordered_messages'=>true,'run_states_verified'=>true,'replay_fenced'=>true,'external_delivery'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo "Native conditional mail: multiple notifications/autoresponse, normalized nested conditions, explicit order, succeeded/skipped runs and replay passed without delivery.\n";
})();
