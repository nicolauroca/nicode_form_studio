<?php
declare(strict_types=1);

// Included in the real SQL/JobRepository/Joomla MIME retry fixture.
(static function()use($forms,$factory,$submissions,$connection,$engine,$runs,$retries,$jobs,$handler):void {
    foreach(['email_notification','email_autoresponse'] as $type) {
        foreach(['es-MX'=>'exact','es-AR'=>'primary','fr-FR'=>'default','de-DE'=>'source'] as $locale=>$expected) {
            $form=$forms->create('Original form','locale-retry-'.bin2hex(random_bytes(6)),1);
            $draft=$forms->draft($form); $field=Nicode\FormStudio\Domain\Uuid::create(); $email=Nicode\FormStudio\Domain\Uuid::create(); $action=Nicode\FormStudio\Domain\Uuid::create();
            $draft['base_language']='en-GB';
            $draft['elements']=[['uuid'=>$field,'type'=>'field'],['uuid'=>$email,'type'=>'field']];
            $draft['fields']=[['uuid'=>$field,'name'=>'answer','type'=>'text','config'=>['label'=>'Original label']],['uuid'=>$email,'name'=>'email','type'=>'email','config'=>['label'=>'Email']]];
            $content=static fn(string $label):array=>['subject'=>$label.' {{form.name}} {{submission.reference}}','body_text'=>$label.' {{field.'.$field.'.label}}: {{field.'.$field.'.value}}','body_html'=>'<p>'.$label.' {{field.'.$field.'.value}}</p>'];
            $draft['actions']=[['uuid'=>$action,'type'=>$type,'failure_policy'=>'blocking','config'=>$content('source')+['to'=>['owner@example.test'],'email_field'=>$email]]];
            if($expected!=='source') {
                foreach(['es-MX'=>'exact','es'=>'primary','en-GB'=>'default'] as $language=>$label) {
                    $draft['translations'][$language]=['form'=>['name'=>$label.' form'],'fields'=>[$field=>['label'=>$label.' label']],'actions'=>[$action=>$content($label)]];
                }
            }
            $revision=$forms->saveDraft($form,0,$draft,1); $version=$forms->publish($form,$revision,1); $spec=$forms->version($form,$version);
            $values=[$field=>'<original & value>',$email=>'visitor@example.test'];
            $response=$submissions->persist($form,$version,$spec,$values,hash('sha256',random_bytes(32)),['locale'=>$locale]);
            $row=$submissions->get($form,$response->id);
            $context=new Nicode\FormStudio\Actions\ActionContext($spec,$values,$response->uuid,$row['received_at'],locale:$locale);
            $factory->mode='preparation'; $before=count($factory->messages);
            $engine->execute($response->id,$context);
            if($runs->latest($response->id,$action)['result_code']!=='mail_preparation_failed') { throw new RuntimeException('Localized retry fixture did not fail definitively.'); }
            $job=$retries->enqueue(1,$form,$response->id,[$action=>1]);
            $draft['name']='Later form'; $draft['base_language']='de-DE'; $draft['translations']=[];
            $draft['fields'][0]['config']['label']='Later label';
            $draft['actions'][0]['config']['subject']='Later subject'; $draft['actions'][0]['config']['body_text']='Later body';
            $revision=$forms->saveDraft($form,(int)$forms->get($form)['draft_revision'],$draft,1); $forms->publish($form,$revision,1);
            $factory->mode='prepared'; $lease=$jobs->claim();
            if($lease?->id!==$job) { throw new RuntimeException('Unexpected localized retry job.'); }
            $jobs->checkpoint($lease,$handler->run($lease,1));
            $mail=$factory->messages[$before+1]??null;
            $title=$expected==='source'?'Original form':$expected.' form'; $label=$expected==='source'?'Original label':$expected.' label';
            if(count($factory->messages)!==$before+2 || $mail->Subject!==$expected.' '.$title.' '.$response->uuid || $mail->Body!=='<p>'.$expected.' &lt;original &amp; value&gt;</p>' || $mail->AltBody!==$expected.' '.$label.': <original & value>' || $mail->getToAddresses()!==[[$type==='email_notification'?'owner@example.test':'visitor@example.test','']]) { throw new RuntimeException('Retry lost original locale, tokens, routing or MIME bodies: '.$type.'/'.$locale); }
            $run=$runs->latest($response->id,$action); $stored=$submissions->get($form,$response->id);
            if($run['state']!=='succeeded' || (int)$run['attempt']!==2 || $stored['locale']!==$locale || (int)$stored['form_version_id']!==$version || $jobs->get($job)['state']!=='completed') { throw new RuntimeException('Localized retry lost history or completion state.'); }
            $engine->execute($response->id,$context,true);
            if(count($factory->messages)!==$before+2 || $retries->eligible(1,$form,$response->id)!==[]) { throw new RuntimeException('Localized retry success was replayed.'); }
        }
    }
    echo "Localized mail retries: both actions, exact/primary/default/source fallback, historical tokens and MIME, original locale/version and success fencing passed.\n";
})();
