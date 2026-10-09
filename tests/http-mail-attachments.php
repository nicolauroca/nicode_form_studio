<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/lib_nicode_form_studio/autoload.php';
require __DIR__.'/repeated-upload-support.php';
$mail=new class implements Nicode\FormStudio\Contract\MailTransportInterface { public function send(Nicode\FormStudio\Actions\MailMessage $message):void { throw new LogicException('CLI fixture never sends.'); } };
$services=repeatedUploadServices(mail:$mail); extract($services,EXTR_SKIP); $root=dirname(__DIR__);
$auth=json_decode(ltrim(file_get_contents($root.'/build/upload-test.json'),"\xEF\xBB\xBF"),true,flags:JSON_THROW_ON_ERROR);
$path=$root.'/build/mail-upload-evidence.txt'; file_put_contents($path,'multipart evidence');
$baseline=glob($root.'/build/repeated-upload-private/*'); $owned=[]; $cases=[];
$send=static function(array $body)use($auth):array {
    $curl=curl_init('http://127.0.0.1:13369/mail-attachments');
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['X-Test-Nonce: '.$auth['nonce']],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_PROXY=>'']);
    $bytes=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    if($bytes===false || $status!==200) { throw new RuntimeException('Mail upload fixture HTTP failure: '.$status); }
    return json_decode($bytes,true,flags:JSON_THROW_ON_ERROR);
};
try {
    foreach([['full',true],['full',false],['metadata',true],['none',true]] as [$mode,$persist]) {
        foreach(['file','multiple-files'] as $type) {
          foreach(['success', 'definite', 'unknown'] as $outcome) {
            $form=$forms->create('Multipart mail','mail-upload-'.bin2hex(random_bytes(5)),1); $draft=$forms->draft($form);
            $field=Nicode\FormStudio\Domain\Uuid::create();
            $draft['elements']=[['uuid'=>$field,'type'=>'field']];
            $draft['fields']=[['uuid'=>$field,'name'=>'evidence','type'=>$type,'persist'=>$persist,'sensitive'=>true,'include_email'=>true,'config'=>['extensions'=>['txt'],'mime_types'=>['text/plain'],'max_bytes'=>1024,'max_files'=>$type==='file'?1:2]]];
            $draft['persistence']['mode']=$mode;
            $draft['actions']=[['uuid'=>Nicode\FormStudio\Domain\Uuid::create(),'type'=>'email_notification','failure_policy'=>'blocking','config'=>['to'=>['to@example.test'],'subject'=>'Evidence','body_text'=>'Received','attachment_fields'=>[$field]]]];
            $revision=$forms->saveDraft($form,0,$draft,1); $version=$forms->publish($form,$revision,1);
            file_put_contents($root.'/build/mail-upload-test.json',json_encode(compact('form','version','outcome'),JSON_THROW_ON_ERROR));
            $body=['form_id'=>(string)$form,'version_id'=>(string)$version,'attempt'=>$attempts->issue($form,$version,'multipart-fixture:component'),'nfs_values'=>'{}'];
            $body['nfs['.$field.'][0]']=new CURLFile($path,'forged/browser-type','first.txt');
            $expected=[['name'=>'first.txt','mime'=>'text/plain','bytes'=>base64_encode('multipart evidence')]];
            if($type==='multiple-files') { $body['nfs['.$field.'][1]']=new CURLFile($path,'forged/browser-type','second.txt'); $expected[]=['name'=>'second.txt','mime'=>'text/plain','bytes'=>base64_encode('multipart evidence')]; }
            $bad=$body; $bad['attempt']=$attempts->issue($form,$version,'multipart-fixture:component');
            $bad['nfs['.$field.']['.($type==='file'?0:1).']']=new CURLFile($path,'text/plain','forbidden.php');
            $rejected=$send($bad);
            if(($rejected['result']['category']??null)!=='upload_error' || $rejected['messages']!==[]) { throw new RuntimeException('Rejected multipart upload reached mail.'); }
            $reply=$send($body);
            if(!($reply['result']['accepted']??false) || ($reply['result']['processed']??null)!==($outcome==='success') || $reply['result']['category']!==($outcome==='success'?'success':'action_blocking_failure') || $reply['messages']!==[$expected]) { throw new RuntimeException('Multipart attachment action failed: '.json_encode($reply)); }
            $rows=$db->rows('SELECT f.storage_key FROM '.$db->table('submission_files').' f JOIN '.$db->table('submissions').' s ON s.id=f.submission_id WHERE s.uuid=:uuid',[':uuid'=>$reply['result']['reference']]);
            array_push($owned,...array_column($rows,'storage_key'));
            if(count($rows)!==($mode==='full'&&$persist?count($expected):0)) { throw new RuntimeException('Attachment persistence policy mismatch.'); }
            $replay=$send($body);
            if(!($replay['result']['replayed']??false) || $replay['result']['reference']!==$reply['result']['reference'] || $replay['result']['category']!==$reply['result']['category'] || $replay['messages']!==[]) { throw new RuntimeException('Multipart replay resent email or changed outcome.'); }
            if($db->rows('SELECT id FROM '.$db->table('upload_staging').' WHERE form_id=:form',[':form'=>$form])!==[]) { throw new RuntimeException('Upload staging remained after action/replay.'); }
            $actual=glob($root.'/build/repeated-upload-private/*'); $expectedPaths=array_merge($baseline,array_map(static fn($key)=>$root.'/build/repeated-upload-private/'.$key,$owned)); sort($actual); sort($expectedPaths);
            if($actual!==$expectedPaths) { throw new RuntimeException('Ephemeral or replay upload leaked.'); }
            $cases[]=['mode'=>$mode,'persist'=>$persist,'type'=>$type,'outcome'=>$outcome,'files'=>count($expected),'invalid_upload_no_send'=>true,'replay_no_send'=>true,'cleanup_verified'=>true];
          }
        }
    }
    file_put_contents($root.'/build/mail-upload-results.json',json_encode(['passed'=>true,'timestamp'=>gmdate(DATE_ATOM),'cases'=>$cases],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo "Multipart mail attachments: 24 published success/definite/unknown cases, exact bytes/order/MIME, persistence policies, replay fencing and physical cleanup passed without delivery.\n";
} finally { foreach($owned as $key) { $storage->delete($key); } if(is_file($path)) { unlink($path); } }
