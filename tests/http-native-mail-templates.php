<?php
declare(strict_types=1);

(static function()use($root,$runtime,$forms,$db,$admin,$request,$captures,&$created):void {
    $templates=$runtime->get(Nicode\FormStudio\Application\Templates::class);
    $actor=(int)$admin->id; $cases=[];
    foreach(['exact','primary','default','source'] as $fallback) {
        $form=$forms->create('Native attachment acceptance','native-mail-template-'.bin2hex(random_bytes(5)),$actor); $created[]=$form;
        $draft=$forms->edit($form,$actor)['draft'];
        $field=Nicode\FormStudio\Domain\Uuid::create(); $email=Nicode\FormStudio\Domain\Uuid::create();
        $draft['elements']=[['uuid'=>$field,'type'=>'field'],['uuid'=>$email,'type'=>'field']];
        $draft['fields']=[['uuid'=>$field,'name'=>'answer','type'=>'text','config'=>['label'=>'Answer']],['uuid'=>$email,'name'=>'email','type'=>'email','config'=>['label'=>'Email','required'=>true]]];
        $draft['base_language']='es-ES'; $draft['security']['captcha']=['mode'=>'none']; $draft['security']['minimum_seconds']=0;
        $revision=$forms->save($form,0,$draft,$actor);
        $template=$templates->createEmail($actor,'Native template acceptance');
        $content=['subject'=>'source {{submission.reference}}','body_text'=>'source {{input.answer.value}}','body_html'=>'<p>source {{input.answer.value}}</p>'];
        $templates->saveEmail($actor,$template,0,'Native template acceptance','es-ES',$content);
        $copied=$templates->emailConfiguration($actor,$template,1,$form,['answer'=>$field])['config'];
        $draft['actions']=[];
        foreach(['email_notification','email_autoresponse'] as $order=>$type) {
            $action=Nicode\FormStudio\Domain\Uuid::create();
            $draft['actions'][]=['uuid'=>$action,'type'=>$type,'order'=>$order,'failure_policy'=>'blocking','config'=>$copied+['to'=>['team@example.test'],'email_field'=>$email]];
            foreach(['en-GB'=>'exact','en'=>'primary','es-ES'=>'default'] as $locale=>$label) {
                if(array_search($label,['exact','primary','default','source'],true)<array_search($fallback,['exact','primary','default','source'],true)) { continue; }
                $draft['translations'][$locale]['actions'][$action]=['subject'=>$label.' {{submission.reference}}','body_text'=>$label.' {{field.'.$field.'.value}}','body_html'=>'<p>'.$label.' {{field.'.$field.'.value}}</p>'];
            }
        }
        $revision=$forms->save($form,$revision,$draft,$actor); $forms->publish($form,$revision,$actor);
        // Neither mutable resource edits nor unpublished action edits may alter this snapshot.
        $templates->saveEmail($actor,$template,1,'Changed template','en-GB',['subject'=>'CHANGED','body_text'=>'CHANGED']);
        $changed=$draft; $changed['translations']=[];
        foreach($changed['actions'] as &$action) { $action['config']['subject']='CHANGED'; $action['config']['body_text']='CHANGED'; } unset($action);
        $published=$forms->edit($form,$actor);
        $forms->save($form,(int)$published['form']['draft_revision'],$changed,$actor);
        [$status,$html]=$request('/index.php?option=com_nicode_form_studio&view=form&id='.$form);
        $document=new DOMDocument(); $prior=libxml_use_internal_errors(true); $document->loadHTML($html); libxml_clear_errors(); libxml_use_internal_errors($prior); $xp=new DOMXPath($document); $node=$xp->query('//form[@data-nfs-form]')->item(0);
        if($status!==200 || !$node instanceof DOMElement) { throw new RuntimeException('Template fixture did not render.'); }
        $post=['format'=>'json','nfs['.$field.']'=>'<value & data>','nfs['.$email.']'=>'visitor@example.test'];
        foreach($xp->query('.//input[@type="hidden"]',$node) as $input) { $post[$input->getAttribute('name')]=$input->getAttribute('value'); }
        $destination=$node->getAttribute('action'); if(str_starts_with($destination,'http')) { $destination=parse_url($destination,PHP_URL_PATH).'?'.parse_url($destination,PHP_URL_QUERY); }
        $before=count($captures()); [$status,$body]=$request($destination,$post); $result=json_decode($body,true,flags:JSON_THROW_ON_ERROR);
        if($status!==200 || !($result['processed']??false) || ($result['category']??null)!=='success') { throw new RuntimeException('Template submission failed.'); }
        $messages=array_map(static fn($line)=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),array_slice($captures(),$before));
        if(count($messages)!==2) { throw new RuntimeException('Template message count mismatch.'); }
        foreach($messages as $index=>$message) {
            if($message['subject']!==$fallback.' '.$result['reference'] || $message['text']!==$fallback.' <value & data>' || $message['html']!=='<p>'.$fallback.' &lt;value &amp; data&gt;</p>' || $message['to']!==[[$index===0?'team@example.test':'visitor@example.test','']]) { throw new RuntimeException('Template fallback, snapshot, token escaping or recipient mismatch: '.$fallback); }
        }
        $row=$db->row('SELECT locale,form_version_id FROM '.$db->table('submissions').' WHERE uuid=:uuid',[':uuid'=>$result['reference']]);
        if($row['locale']!=='en-GB') { throw new RuntimeException('Template response locale not retained.'); }
        [$status,$body]=$request($destination,$post); $replay=json_decode($body,true,flags:JSON_THROW_ON_ERROR);
        if(!($replay['replayed']??false) || $replay['reference']!==$result['reference'] || count($captures())!==$before+2) { throw new RuntimeException('Template replay resent mail.'); }
        $cases[]=compact('form','template','fallback');
    }
    file_put_contents($root.'/build/native-mail-template-results.json',json_encode(['passed'=>true,'timestamp'=>gmdate(DATE_ATOM),'cases'=>$cases,'published_copy_isolated'=>true,'external_delivery'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo "Native mail templates: both actions, exact/primary/default/source fallback, bound tokens, HTML escaping, published copy isolation and replay passed without delivery.\n";
})();
