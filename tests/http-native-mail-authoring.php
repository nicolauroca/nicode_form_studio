<?php
declare(strict_types=1);

// Optional acceptance of the synthetic form edited through the native builder.
(static function()use($argv,$root,$forms,$repository,$db,$admin,$request,$captures,&$created):void {
    if(!ctype_digit($argv[1])) { throw new InvalidArgumentException('Expected numeric authoring fixture ID.'); }
    $form=(int)$argv[1]; $record=$repository->get($form);
    if($record['name']!=='Native attachment acceptance' || !str_starts_with($record['alias'],'native-mail-conditions-') || $record['state']!=='published') { throw new RuntimeException('Refusing non-fixture authoring acceptance.'); }
    $created[]=$form;
    $spec=$repository->version($form,(int)$record['published_version_id'])->toArray();
    $fields=array_column($spec['fields'],'uuid','name'); $route=$fields['route']; $email=$fields['email'];
    if(count($spec['actions'])!==4) { throw new RuntimeException('Expected four UI-authored actions.'); }
    foreach(['receipt','support','neither'] as $choice) {
        [$status,$html]=$request('/index.php?option=com_nicode_form_studio&view=form&id='.$form);
        $document=new DOMDocument(); $prior=libxml_use_internal_errors(true); $document->loadHTML($html); libxml_clear_errors(); libxml_use_internal_errors($prior); $xp=new DOMXPath($document); $node=$xp->query('//form[@data-nfs-form]')->item(0);
        if($status!==200 || !$node instanceof DOMElement) { throw new RuntimeException('UI-authored form did not render.'); }
        $post=['format'=>'json','nfs['.$route.']'=>$choice,'nfs['.$email.']'=>'visitor@example.test'];
        foreach($xp->query('.//input[@type="hidden"]',$node) as $input) { $post[$input->getAttribute('name')]=$input->getAttribute('value'); }
        $destination=$node->getAttribute('action'); if(str_starts_with($destination,'http')) { $destination=parse_url($destination,PHP_URL_PATH).'?'.parse_url($destination,PHP_URL_QUERY); }
        $before=count($captures()); [$status,$body]=$request($destination,$post); $result=json_decode($body,true,flags:JSON_THROW_ON_ERROR);
        if($status!==200 || !($result['processed']??false) || ($result['category']??null)!=='success') { throw new RuntimeException('UI-authored mail submission failed.'); }
        $expected=match($choice) {'receipt'=>[['common','common@example.test'],['Visitor receipt','visitor@example.test']],'support'=>[['common','common@example.test'],['Team review','team@example.test'],['Support receipt','visitor@example.test']],default=>[]};
        $messages=array_map(static fn($line)=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),array_slice($captures(),$before));
        if(count($messages)!==count($expected)) { throw new RuntimeException('UI-authored mail count mismatch.'); }
        foreach($messages as $index=>$message) {
            if($message['subject']!==$expected[$index][0].' '.$result['reference'] || $message['to']!==[[$expected[$index][1],'']]) { throw new RuntimeException('UI-authored conditional order or recipient mismatch.'); }
        }
        $runs=$db->rows('SELECT a.state,a.result_code,a.attempt FROM '.$db->table('action_runs').' a JOIN '.$db->table('submissions').' s ON s.id=a.submission_id WHERE s.uuid=:uuid ORDER BY a.id',[':uuid'=>$result['reference']]);
        if(count($runs)!==4 || count(array_filter($runs,static fn($run)=>$run['state']==='succeeded' && $run['result_code']==='mail_sent' && (int)$run['attempt']===1))!==count($expected) || count(array_filter($runs,static fn($run)=>$run['state']==='skipped' && $run['result_code']==='condition_false'))!==4-count($expected)) { throw new RuntimeException('UI-authored run outcome mismatch.'); }
        [$status,$body]=$request($destination,$post); $replay=json_decode($body,true,flags:JSON_THROW_ON_ERROR);
        if(!($replay['replayed']??false) || $replay['reference']!==$result['reference'] || count($captures())!==$before+count($expected)) { throw new RuntimeException('UI-authored mail replay duplicated effects.'); }
    }
    file_put_contents($root.'/build/native-mail-authoring-results.json',json_encode(['passed'=>true,'timestamp'=>gmdate(DATE_ATOM),'form'=>$form,'version'=>(int)$record['published_version_id'],'choices'=>['receipt','support','neither'],'external_delivery'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo "Native UI-authored mail: four published actions, edited branches and subjects, new autoresponse, recipients, order, runs and replay passed without delivery.\n";
})();
