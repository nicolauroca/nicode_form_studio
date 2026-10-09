<?php
declare(strict_types=1);

require __DIR__.'/repeated-upload-support.php';
$root=dirname(__DIR__); $services=repeatedUploadServices(); extract($services,EXTR_SKIP);
$auth=json_decode(ltrim(file_get_contents($root.'/build/upload-test.json'),"\xEF\xBB\xBF"),true,flags:JSON_THROW_ON_ERROR);
$form=$forms->create('Repeated HTTP upload','repeated-http-'.bin2hex(random_bytes(5)),1); $data=$forms->draft($form);
[$group,$text,$file,$one,$two]=array_map(static fn()=>Nicode\FormStudio\Domain\Uuid::create(),range(1,5));
$data['elements']=[['uuid'=>$group,'type'=>'repeatable-group','repeat'=>['min'=>1,'max'=>2]],['uuid'=>$text,'type'=>'field','parent_uuid'=>$group],['uuid'=>$file,'type'=>'field','parent_uuid'=>$group]];
$data['fields']=[['uuid'=>$text,'name'=>'name','type'=>'text','config'=>['required'=>true]],['uuid'=>$file,'name'=>'attachment','type'=>'file','config'=>['extensions'=>['txt'],'mime_types'=>['text/plain'],'max_bytes'=>1024]]];
$data['rules']=[['uuid'=>Nicode\FormStudio\Domain\Uuid::create(),'when'=>['field'=>$text,'operator'=>'equals','value'=>'skip'],'effects'=>[['target'=>$file,'type'=>'hide']]]];
$data['actions']=[['uuid'=>Nicode\FormStudio\Domain\Uuid::create(),'type'=>'email_notification','failure_policy'=>'blocking','config'=>['to'=>['capture@example.test'],'subject'=>'Repeated evidence','body_text'=>'Synthetic row attachments','attachment_fields'=>[$file]]]];
$spec=new Nicode\FormStudio\Domain\FormSpec($data);
$version=$db->insert('form_versions',['form_id'=>$form,'revision'=>1,'schema_version'=>'1.0','spec'=>Nicode\FormStudio\Domain\CanonicalJson::encode($data),'hash'=>$spec->hash,'published_at'=>gmdate('Y-m-d H:i:s'),'published_by'=>1,'comment'=>'Internal HTTP snapshot','revoked_at'=>null]);
$db->execute('UPDATE '.$db->table('forms')." SET state = 'published', published_version_id = :version, access = 1, language = '*' WHERE id = :id",[':version'=>$version,':id'=>$form]);
file_put_contents($root.'/build/repeated-upload-test.json',json_encode(compact('form','version'),JSON_THROW_ON_ERROR));
$rows=[$group=>[$one,$two]]; $key=static fn($id,$row)=>$group.'/'.$row.'/'.$id;
$first=$root.'/build/repeated-upload-one.txt'; $second=$root.'/build/repeated-upload-two.txt';
file_put_contents($first,'first row bytes'); file_put_contents($second,'second row bytes');
$baseline=glob($root.'/build/repeated-upload-private/*'); $owned=[];
$send=static function(array $body)use($auth):array {
    $curl=curl_init('http://127.0.0.1:13369/repeated');
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['X-Test-Nonce: '.$auth['nonce']],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_PROXY=>'']);
    $bytes=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    if($bytes===false) { throw new RuntimeException('Repeated upload HTTP fixture unavailable.'); }
    return [$status,json_decode($bytes,true,flags:JSON_THROW_ON_ERROR)];
};
$base=['form_id'=>(string)$form,'version_id'=>(string)$version,'nfs_instances'=>json_encode($rows),'nfs_values'=>json_encode([$key($text,$one)=>'One',$key($text,$two)=>'Two'])];
$uploads=['nfs['.$key($file,$one).']'=>new CURLFile($first,'forged/browser-mime','one.txt'),'nfs['.$key($file,$two).']'=>new CURLFile($second,'forged/browser-mime','two.txt')];
$fresh=static fn()=>$attempts->issue($form,$version,'multipart-fixture:component');
$clean=static function()use($db,$form,$root,$baseline,&$owned):void {
    if($db->rows('SELECT id FROM '.$db->table('upload_staging').' WHERE form_id = :form',[':form'=>$form])!==[]) { throw new RuntimeException('Repeated upload staging not consumed or transferred.'); }
    $actual=glob($root.'/build/repeated-upload-private/*'); $expected=array_merge($baseline,array_map(static fn($key)=>$root.'/build/repeated-upload-private/'.$key,$owned)); sort($actual); sort($expected);
    if($actual!==$expected) { throw new RuntimeException('Repeated upload leaked unowned private bytes.'); }
};
try {
    $token=$fresh(); [$status,$result]=$send($base+$uploads+['attempt'=>$token]);
    if($status!==200 || !($result['accepted']??false)) { throw new RuntimeException('Repeated multipart failed: '.json_encode($result)); }
    $expected=[['name'=>'one.txt','mime'=>'text/plain','bytes'=>base64_encode('first row bytes')],['name'=>'two.txt','mime'=>'text/plain','bytes'=>base64_encode('second row bytes')]];
    if(!($result['processed']??false) || ($result['fixture_messages']??null)!==[$expected]) { throw new RuntimeException('Repeated action lost ordered row attachments.'); }
    $files=$db->rows('SELECT f.* FROM '.$db->table('submission_files').' f JOIN '.$db->table('submissions').' s ON s.id = f.submission_id WHERE s.uuid = :uuid ORDER BY f.id',[':uuid'=>$result['reference']]);
    $owned=array_column($files,'storage_key'); if(count($files)!==2) { throw new RuntimeException('Repeated multipart lost files.'); }
    foreach($files as $index=>$entry) {
        $path=$group.'/'.[$one,$two][$index]; $stream=$storage->open($entry['storage_key']); $bytes=stream_get_contents($stream); fclose($stream);
        if($entry['instance_path']!==$path || $entry['instance_hash']!==hash('sha256',$path) || $entry['field_uuid']!==$file || $entry['mime']!=='text/plain' || $bytes!==($index===0?'first row bytes':'second row bytes')) { throw new RuntimeException('Repeated multipart byte or scope mismatch.'); }
    }
    $clean();
    $providers=new Nicode\FormStudio\Registry\StorageProviderRegistry(); $providers->register($storage);
    $resolver=new Nicode\FormStudio\Application\StoredMailAttachments($db,$providers);
    $storedContext=new Nicode\FormStudio\Actions\ActionContext($spec,[],$result['reference'],gmdate('Y-m-d H:i:s'),instances:$rows);
    if(array_column($resolver->resolve([$file],$storedContext),'bytes')!==['first row bytes','second row bytes']) { throw new RuntimeException('Stored repeated attachment resolution lost row order.'); }
    $db->execute('UPDATE '.$db->table('submission_files').' SET instance_path=:path, instance_hash=:hash WHERE id=:id',[':path'=>$group.'/'.$two,':hash'=>hash('sha256',$group.'/'.$two),':id'=>(int)$files[0]['id']]);
    try {
        try { $resolver->resolve([$file],$storedContext); throw new RuntimeException('Stored attachment accepted another row scope.'); }
        catch(Nicode\FormStudio\Actions\ActionFailure $failure) { if($failure->resultCode!=='mail_attachment_unavailable' || $failure->unknownOutcome) { throw new RuntimeException('Unsafe repeated scope failure.'); } }
    } finally { $db->execute('UPDATE '.$db->table('submission_files').' SET instance_path=:path, instance_hash=:hash WHERE id=:id',[':path'=>$files[0]['instance_path'],':hash'=>$files[0]['instance_hash'],':id'=>(int)$files[0]['id']]); }
    [, $replay]=$send($base+$uploads+['attempt'=>$token,'reject_captcha'=>'1']);
    if(!($replay['replayed']??false) || $replay['reference']!==$result['reference'] || $replay['fixture_messages']!==[]) { throw new RuntimeException('Real repeated file replay was not stable or resent mail.'); } $clean();
    $cases=[
        [$base+$uploads+['attempt'=>$fresh(),'reject_captcha'=>'1'],'captcha_error'],
        [array_replace($base,['nfs_values'=>json_encode([$key($text,$two)=>'Two'])])+$uploads+['attempt'=>$fresh()],'validation_error'],
        [$base+array_replace($uploads,['nfs['.$key($file,$two).']'=>new CURLFile($second,'text/plain','evil.php')])+['attempt'=>$fresh()],'upload_error'],
        [array_replace($base,['nfs_values'=>json_encode([$key($text,$one)=>'Changed',$key($text,$two)=>'Two'])])+$uploads+['attempt'=>$token],'session_error'],
    ];
    foreach($cases as [$body,$category]) { [, $failure]=$send($body); if(($failure['category']??null)!==$category || ($failure['accepted']??false) || $failure['fixture_messages']!==[]) { throw new RuntimeException('Unexpected repeated upload failure: '.json_encode($failure)); } $clean(); }
    $inactive=array_replace($base,['nfs_values'=>json_encode([$key($text,$one)=>'skip',$key($text,$two)=>'Two'])]);
    [, $inactiveResult]=$send($inactive+$uploads+['attempt'=>$fresh()]);
    if(!($inactiveResult['accepted']??false)) { throw new RuntimeException('Inactive repeated upload rejected entire form.'); }
    if(($inactiveResult['fixture_messages']??null)!==[[$expected[1]]]) { throw new RuntimeException('Inactive row attachment leaked into mail.'); }
    $activeFiles=$db->rows('SELECT f.* FROM '.$db->table('submission_files').' f JOIN '.$db->table('submissions').' s ON s.id = f.submission_id WHERE s.uuid = :uuid',[':uuid'=>$inactiveResult['reference']]);
    array_push($owned,...array_column($activeFiles,'storage_key'));
    if(count($activeFiles)!==1 || $activeFiles[0]['instance_path']!==$group.'/'.$two) { throw new RuntimeException('Inactive row file was attached or active sibling lost.'); } $clean();
    $reversedRows=[$group=>[$two,$one]];
    [, $reversed]=$send(array_replace($base,['nfs_instances'=>json_encode($reversedRows)])+$uploads+['attempt'=>$fresh()]);
    if(!($reversed['processed']??false) || $reversed['fixture_messages']!==[array_reverse($expected)]) { throw new RuntimeException('Attachment order followed upload order instead of declared row order.'); }
    $reversedFiles=$db->rows('SELECT f.storage_key FROM '.$db->table('submission_files').' f JOIN '.$db->table('submissions').' s ON s.id=f.submission_id WHERE s.uuid=:uuid',[':uuid'=>$reversed['reference']]);
    array_push($owned,...array_column($reversedFiles,'storage_key'));
    $reversedContext=new Nicode\FormStudio\Actions\ActionContext($spec,[],$reversed['reference'],gmdate('Y-m-d H:i:s'),instances:$reversedRows);
    if(array_column($resolver->resolve([$file],$reversedContext),'bytes')!==['second row bytes','first row bytes']) { throw new RuntimeException('Stored resolver did not preserve reordered row declarations.'); } $clean();
    $foreign=$group.'/'.Nicode\FormStudio\Domain\Uuid::create().'/'.$file;
    [$foreignStatus]=$send($base+['attempt'=>$fresh(),'nfs['.$foreign.']'=>new CURLFile($first,'text/plain','foreign.txt')]);
    if($foreignStatus!==422) { throw new RuntimeException('Native upload to undeclared row accepted.'); } $clean();
    // A separate snapshot proves multivalue files remain distinct from row count.
    $data['fields'][1]['type']='multiple-files'; $data['fields'][1]['config']['max_files']=2;
    $multiSpec=new Nicode\FormStudio\Domain\FormSpec($data);
    $multiVersion=$db->insert('form_versions',['form_id'=>$form,'revision'=>2,'schema_version'=>'1.0','spec'=>Nicode\FormStudio\Domain\CanonicalJson::encode($data),'hash'=>$multiSpec->hash,'published_at'=>gmdate('Y-m-d H:i:s'),'published_by'=>1,'comment'=>'Internal multiple upload snapshot','revoked_at'=>null]);
    $db->execute('UPDATE '.$db->table('forms').' SET published_version_id = :version WHERE id = :id',[':version'=>$multiVersion,':id'=>$form]);
    file_put_contents($root.'/build/repeated-upload-test.json',json_encode(['form'=>$form,'version'=>$multiVersion],JSON_THROW_ON_ERROR));
    $multiBase=array_replace($base,['version_id'=>(string)$multiVersion]);
    $multiUploads=['nfs['.$key($file,$one).'][0]'=>new CURLFile($first,'text/plain','first.txt'),'nfs['.$key($file,$one).'][1]'=>new CURLFile($second,'text/plain','second.txt'),'nfs['.$key($file,$two).'][0]'=>new CURLFile($first,'text/plain','other.txt')];
    [, $multiResult]=$send($multiBase+$multiUploads+['attempt'=>$attempts->issue($form,$multiVersion,'multipart-fixture:component')]);
    if(!($multiResult['accepted']??false)) { throw new RuntimeException('Repeated multiple-file upload failed: '.json_encode($multiResult)); }
    if(array_column($multiResult['fixture_messages'][0]??[],'name')!==['first.txt','second.txt','other.txt'] || array_column($multiResult['fixture_messages'][0]??[],'bytes')!==array_map('base64_encode',['first row bytes','second row bytes','first row bytes'])) { throw new RuntimeException('Repeated multiple-file action lost canonical attachment order.'); }
    $multiFiles=$db->rows('SELECT f.storage_key FROM '.$db->table('submission_files').' f JOIN '.$db->table('submissions').' s ON s.id = f.submission_id WHERE s.uuid = :uuid',[':uuid'=>$multiResult['reference']]);
    array_push($owned,...array_column($multiFiles,'storage_key'));
    $multiPayload=json_decode($db->row('SELECT canonical_payload FROM '.$db->table('submissions').' WHERE uuid = :uuid',[':uuid'=>$multiResult['reference']])['canonical_payload'],true);
    if(count($multiFiles)!==3 || array_column($multiPayload['values'][$key($file,$one)],'name')!==['first.txt','second.txt'] || count($multiPayload['values'][$key($file,$two)])!==1) { throw new RuntimeException('Multiple files lost their row or canonical order.'); } $clean();
    $tooMany=$multiUploads+['nfs['.$key($file,$one).'][2]'=>new CURLFile($first,'text/plain','excess.txt')];
    [, $limitResult]=$send($multiBase+$tooMany+['attempt'=>$attempts->issue($form,$multiVersion,'multipart-fixture:component')]);
    if(($limitResult['category']??null)!=='upload_error' || $limitResult['fixture_messages']!==[]) { throw new RuntimeException('Per-instance multiple-file limit bypassed or reached mail.'); } $clean();
    file_put_contents($root.'/build/repeated-mail-attachment-results.json',json_encode(['passed'=>true,'timestamp'=>gmdate(DATE_ATOM),'staged_row_order'=>true,'stored_row_order'=>true,'reordered_rows_verified'=>true,'foreign_scope_rejected'=>true,'inactive_omitted'=>true,'multivalue_order'=>true,'replay_no_send'=>true,'failure_no_send'=>true,'publication_guard_unchanged'=>true],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    file_put_contents($root.'/build/repeated-upload-results.json',json_encode(['passed'=>true,'timestamp'=>gmdate(DATE_ATOM),'scenarios'=>['native multipart provenance','exact row ownership','byte MIME','stable replay','CAPTCHA cleanup','validation cleanup','partial upload cleanup','changed replay cleanup','inactive file isolation','undeclared row rejection','per-row multiple file order/count']],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo "Native repeated multipart: exact row ownership, byte MIME, replay and all failure/inactive cleanup scenarios passed.\n";
} finally {
    foreach($owned as $storedKey) { $storage->delete($storedKey); }
    foreach([$first,$second] as $path) { if(is_file($path)) { unlink($path); } }
}
