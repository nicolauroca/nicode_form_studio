<?php
declare(strict_types=1);
$root=dirname(__DIR__); $site=$root.'/build/joomla-6.0.0';
$_SERVER['HTTP_HOST']='127.0.0.1:13371'; $_SERVER['REQUEST_URI']='/'; $_SERVER['SCRIPT_NAME']='/index.php'; $_SERVER['PHP_SELF']='/index.php';
define('_JEXEC',1); define('JPATH_BASE',$site);
require $site.'/includes/defines.php'; require $site.'/includes/framework.php'; require $root.'/src/lib_nicode_form_studio/autoload.php';
$container=Joomla\CMS\Factory::getContainer();
$container->alias('session','session.cli')->alias(Joomla\CMS\Session\Session::class,'session.cli')->alias(Joomla\Session\SessionInterface::class,'session.cli');
$app=$container->get(Joomla\CMS\Application\SiteApplication::class); Joomla\CMS\Factory::$application=$app; $app->createExtensionNamespaceMap();
if($app->get('db')!=='formstudio_joomla' || $app->get('host')!=='127.0.0.1:13367') { throw new RuntimeException('Non-isolated fixture.'); }
$app->loadLanguage($container->get(Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('en-GB',false));
$credentials=json_decode(file_get_contents($root.'/build/joomla-test.json'),true,flags:JSON_THROW_ON_ERROR);
$admin=$container->get(Joomla\CMS\User\UserFactoryInterface::class)->loadUserByUsername($credentials['username']); unset($credentials); $app->loadIdentity($admin);
$db=new Nicode\FormStudio\Infrastructure\Database\Connection($container->get(Joomla\Database\DatabaseInterface::class));
$row=$db->row('SELECT params FROM '.$db->quote('#__extensions')." WHERE type='component' AND element='com_nicode_form_studio'");
$params=new Joomla\Registry\Registry($row['params']); $runtime=new Joomla\DI\Container($container); $runtime->registerServiceProvider(new Nicode\FormStudio\Infrastructure\Joomla\RuntimeProvider($app,$params,$site));
$forms=$runtime->get(Nicode\FormStudio\Application\FormAdministration::class); $repository=$runtime->get(Nicode\FormStudio\Infrastructure\Database\FormRepository::class);
$storage=$runtime->get(Nicode\FormStudio\Registry\StorageProviderRegistry::class);
$auth=json_decode(file_get_contents($root.'/build/upload-test.json'),true,flags:JSON_THROW_ON_ERROR);
$jar=$root.'/build/native-mail-cookie.txt'; $file=$root.'/build/native-mail-evidence.txt'; file_put_contents($file,'native component evidence');
file_put_contents($root.'/build/native-mail-capture.jsonl','');
$request=static function(string $path,?array $post=null,string $failure='')use($jar,$auth):array {
    if(!str_starts_with($path,'/index.php')) { throw new RuntimeException('Unexpected fixture route.'); }
    $curl=curl_init('http://127.0.0.1:13375'.$path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar,CURLOPT_PROXY=>'',CURLOPT_HTTPHEADER=>['X-Test-Nonce: '.$auth['nonce'], 'X-Test-Mail-Failure: '.$failure]]);
    if($post!==null) { curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post]); }
    $body=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_setopt($curl,CURLOPT_COOKIELIST,'FLUSH');
    if(!is_string($body)) { throw new RuntimeException('Native mail HTTP failure.'); }
    return [$status,$body];
};
$captures=static fn()=>file($root.'/build/native-mail-capture.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
$created=array_map('intval',array_column($db->rows('SELECT id FROM '.$db->table('forms')." WHERE name=:name AND alias LIKE 'native-mail-%' AND state='published'",[':name'=>'Native attachment acceptance']),'id')); $cases=[];
try {
    if (($argv[1] ?? '') === '--failures') { require __DIR__ . '/http-native-mail-failures.php'; return; }
    foreach([['full',true],['full',false],['metadata',true],['none',true]] as [$mode,$persist]) {
      foreach(['email_notification','email_autoresponse'] as $actionType) {
        $form=$forms->create('Native attachment acceptance','native-mail-'.bin2hex(random_bytes(5)),(int)$admin->id); $created[]=$form;
        $draft=$forms->edit($form,(int)$admin->id)['draft']; $field=Nicode\FormStudio\Domain\Uuid::create(); $action=Nicode\FormStudio\Domain\Uuid::create();
        $draft['elements']=[['uuid'=>$field,'type'=>'field']];
        $draft['fields']=[['uuid'=>$field,'name'=>'evidence','type'=>'multiple-files','persist'=>$persist,'sensitive'=>true,'include_email'=>true,'config'=>['label'=>'Evidence','extensions'=>['txt'],'mime_types'=>['text/plain'],'max_bytes'=>1024,'max_files'=>2]]];
        $email=Nicode\FormStudio\Domain\Uuid::create(); $answer=Nicode\FormStudio\Domain\Uuid::create(); $private=Nicode\FormStudio\Domain\Uuid::create();
        foreach([$email,$answer,$private] as $uuid) { $draft['elements'][]=['uuid'=>$uuid,'type'=>'field']; }
        $draft['fields'][]=['uuid'=>$email,'name'=>'email','type'=>'email','config'=>['label'=>'Email','required'=>true]];
        $draft['fields'][]=['uuid'=>$answer,'name'=>'answer','type'=>'textarea','config'=>['label'=>'Answer']];
        $draft['fields'][]=['uuid'=>$private,'name'=>'private','type'=>'text','sensitive'=>true,'config'=>['label'=>'Private']];
        $draft['persistence']['mode']=$mode; $draft['security']['captcha']=['mode'=>'none']; $draft['security']['minimum_seconds']=0;
        $draft['actions']=[['uuid'=>$action,'type'=>$actionType,'failure_policy'=>'blocking','config'=>['to'=>['capture@example.test'],'email_field'=>$email,'reply_to_field'=>$email,'cc'=>['copy@example.test'],'bcc'=>['hidden@example.test'],'subject'=>'Received {{submission.reference}}','body_text'=>'{{field.'.$answer.'.value}}\n{{response.summary}}','body_html'=>'<p>{{field.'.$answer.'.value}}</p>','attachment_fields'=>[$field]]]];
        $revision=$forms->save($form,0,$draft,(int)$admin->id); $forms->publish($form,$revision,(int)$admin->id);
        [$status,$html]=$request('/index.php?option=com_nicode_form_studio&view=form&id='.$form);
        $document=new DOMDocument(); $prior=libxml_use_internal_errors(true); $document->loadHTML($html); libxml_clear_errors(); libxml_use_internal_errors($prior); $xp=new DOMXPath($document); $node=$xp->query('//form[@data-nfs-form]')->item(0);
        if($status!==200 || !$node instanceof DOMElement) { file_put_contents($root.'/build/native-mail-render.html',$html); throw new RuntimeException('Native form did not render: '.$status); }
        $post=['format'=>'json']; foreach($xp->query('.//input[@type="hidden"]',$node) as $input) { $post[$input->getAttribute('name')]=$input->getAttribute('value'); }
        $post['nfs['.$field.'][0]']=new CURLFile($file,'browser/forged','first.txt'); $post['nfs['.$field.'][1]']=new CURLFile($file,'browser/forged','second.txt');
        $post['nfs['.$email.']']='visitor@example.test'; $post['nfs['.$answer.']']='<strong>literal & text</strong>'; $post['nfs['.$private.']']='PRIVATE-MARKER-MUST-NOT-LEAK';
        $destination=$node->getAttribute('action'); if(str_starts_with($destination,'http')) { $destination=parse_url($destination,PHP_URL_PATH).'?'.parse_url($destination,PHP_URL_QUERY); }
        $before=count($captures()); $invalid=$post; $invalid['nfs['.$email.']']="visitor@example.test\r\nBcc: injected@example.test";
        [$status,$body]=$request($destination,$invalid); $rejected=json_decode($body,true,flags:JSON_THROW_ON_ERROR);
        if($status!==422 || ($rejected['category']??null)!=='validation_error' || count($captures())!==$before) { throw new RuntimeException('Invalid visitor email reached native mail action.'); }
        [$status,$body]=$request($destination,$post); $result=json_decode($body,true,flags:JSON_THROW_ON_ERROR);
        if($status!==200 || !($result['accepted']??false) || !($result['processed']??false)) { throw new RuntimeException('Native attachment submission failed: '.$body); }
        $lines=$captures(); if(count($lines)!==$before+1) { throw new RuntimeException('Native mail was not captured exactly once.'); }
        $captured=json_decode($lines[$before],true,flags:JSON_THROW_ON_ERROR); $mime=base64_decode($captured['mime'],true);
        if($captured['to']!==[[$actionType==='email_autoresponse'?'visitor@example.test':'capture@example.test','']] || $captured['cc']!==[['copy@example.test','']] || $captured['bcc']!==[['hidden@example.test','']] || !isset($captured['reply_to']['visitor@example.test']) || $captured['from']!=='fixture@example.test' || $captured['subject']!=='Received '.$result['reference']) { throw new RuntimeException('Native email routing or trusted sender mismatch.'); }
        [$headers]=preg_split('/\r?\n\r?\n/',$mime,2);
        if(str_contains($headers,'hidden@example.test') || str_contains($mime,'PRIVATE-MARKER-MUST-NOT-LEAK') || $captured['html']!=='<p>&lt;strong&gt;literal &amp; text&lt;/strong&gt;</p>' || !str_contains($captured['text'],'<strong>literal & text</strong>') || !str_contains($mime,'multipart/alternative')) { throw new RuntimeException('Native mail body escaping, privacy or BCC isolation failed.'); }
        if(substr_count($mime,base64_encode('native component evidence'))!==2 || !str_contains($mime,'filename=first.txt') || !str_contains($mime,'filename=second.txt') || substr_count($mime,'Content-Disposition: attachment')!==2 || substr_count($mime,'Content-Type: text/plain; name=')!==2) { throw new RuntimeException('Native MIME lost attachment bytes, names or content types.'); }
        [$status,$body]=$request($destination,$post); $repeat=json_decode($body,true,flags:JSON_THROW_ON_ERROR);
        if(!($repeat['replayed']??false) || $repeat['reference']!==$result['reference'] || count($captures())!==$before+1) { throw new RuntimeException('Native attachment replay resent mail.'); }
        $owned=$db->rows('SELECT f.* FROM '.$db->table('submission_files').' f JOIN '.$db->table('submissions').' s ON s.id=f.submission_id WHERE s.form_id=:form',[':form'=>$form]);
        if(count($owned)!==($mode==='full'&&$persist?2:0) || $db->rows('SELECT id FROM '.$db->table('upload_staging').' WHERE form_id=:form',[':form'=>$form])!==[]) { throw new RuntimeException('Native attachment ownership or cleanup mismatch.'); }
        $runs=$db->rows('SELECT a.state,a.attempt,a.result_code,a.action_uuid FROM '.$db->table('action_runs').' a JOIN '.$db->table('submissions').' s ON s.id=a.submission_id WHERE s.form_id=:form AND s.uuid=:uuid',[':form'=>$form,':uuid'=>$result['reference']]);
        if($mode!=='none' && (count($runs)!==1 || $runs[0]['state']!=='succeeded' || (int)$runs[0]['attempt']!==1 || $runs[0]['result_code']!=='mail_sent' || $runs[0]['action_uuid']!==$action)) { throw new RuntimeException('Native email ActionRun or replay attempt mismatch.'); }
        $cases[]=compact('mode','persist','form','actionType');
      }
    }
    require __DIR__.'/http-native-mail-conditions.php';
    require __DIR__.'/http-native-mail-templates.php';
    if(isset($argv[1])) { require __DIR__.'/http-native-mail-authoring.php'; }
    file_put_contents($root.'/build/native-mail-attachment-results.json',json_encode(['passed'=>true,'timestamp'=>gmdate(DATE_ATOM),'cases'=>$cases,'mime_capture_only'=>true,'mime_verified'=>true,'replay_fenced'=>true],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo "Native Joomla multipart email: both mail actions, four persistence policies, recipients/CC/BCC/Reply-To, escaped HTML/plain alternatives, private summary exclusion, attachments and replay passed without delivery.\n";
} finally {
    foreach($created as $form) {
        foreach($db->rows('SELECT f.provider,f.storage_key FROM '.$db->table('submission_files').' f JOIN '.$db->table('submissions').' s ON s.id=f.submission_id WHERE s.form_id=:form',[':form'=>$form]) as $owned) { $storage->get($owned['provider'])->delete($owned['storage_key']); }
        $record=$repository->get($form); $forms->deactivate($form,(int)$record['draft_revision'],(int)$admin->id,'unpublished');
    }
    foreach([$file,$jar] as $path) { if(is_file($path)) { unlink($path); } }
}
