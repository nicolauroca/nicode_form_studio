<?php
declare(strict_types=1);

// Reuse the preceding internal fixture; no repeated publication is enabled.
$rrData = $rpData;
$rrData['fields'][0]['sensitive'] = true;
$rrData['fields'][0]['include_export'] = true;
$rrData['fields'][0]['allow_sensitive_index'] = true;
$rrData['fields'][0]['config']['label'] = 'Historical secret';
$rrData['fields'][2]['sensitive'] = true;
$rrSpec = new Nicode\FormStudio\Domain\FormSpec($rrData);
$rrVersion = $connection->insert('form_versions', ['form_id'=>$rpForm,'revision'=>4,'schema_version'=>'1.0','spec'=>Nicode\FormStudio\Domain\CanonicalJson::encode($rrData),'hash'=>$rrSpec->hash,'published_at'=>gmdate('Y-m-d H:i:s'),'published_by'=>1,'comment'=>'Internal read snapshot','revoked_at'=>null]);
$rrStream=fopen('php://temp','w+b'); fwrite($rrStream,'abc'); rewind($rrStream); $rrObject=$privateStorage->put($rrStream,100); fclose($rrStream);
$rrReceipt=array_replace($rpReceipt,['uuid'=>Nicode\FormStudio\Domain\Uuid::create()]);
$rrContext=['files'=>[['field_address'=>$rpKey($rpFile,$rpOne),'receipt'=>$rrReceipt,'file'=>$rrObject]],'option_labels'=>[$rpKey($rpText,$rpOne)=>'Private option label']];
$rrValid=$validationEngine->validateInstances($rrSpec,$rpRows,$rpRaw,[$rpKey($rpFile,$rpOne)=>$rrReceipt]);
$rrSaved=$submissions->persistInstances($rpForm,$rrVersion,$rrSpec,$rpRows,$rrValid,hash('sha256',random_bytes(32)),$rrContext);
try {
    $rrMasked=$reader->read($rpForm,$rrSaved->id,2);
    if ($rrMasked['values']!==[] || $rrMasked['option_labels']!==[] || count($rrMasked['masked'])!==4) { throw new RuntimeException('Repeated read leaked sensitive answers or labels.'); }
    $rpReject(fn()=>$reader->read($rpForm,$rrSaved->id,2,revealSensitive:true));
    $rrVisible=$reader->read($rpForm,$rrSaved->id,1,revealSensitive:true);
    if ($rrVisible['values'][$rpKey($rpText,$rpOne)]!=='Uno ñ' || $rrVisible['labels'][$rpKey($rpText,$rpOne)]!=='Historical secret' || $rrVisible['option_labels']!==$rrContext['option_labels']) { throw new RuntimeException('Repeated reveal lost historical values/labels.'); }
    $rrColumns=Nicode\FormStudio\Application\SubmissionColumns::project($rrVisible,[$rpText=>'Current label']);
    if (array_column($rrColumns[$rpText]['value'],'value')!==['Dos','Uno ñ'] || $rrColumns[$rpText]['value'][1]['option_label']!=='Private option label' || $rrColumns[$rpText]['label']!=='Historical secret') { throw new RuntimeException('Repeated columns lost historical row order or labels.'); }
    $rrHiddenColumns=Nicode\FormStudio\Application\SubmissionColumns::project($rrMasked,[$rpText=>'Current label']);
    if (!$rrHiddenColumns[$rpText]['masked'] || $rrHiddenColumns[$rpText]['value']!==null || $rrHiddenColumns[$rpText]['label']!=='Current label') { throw new RuntimeException('Repeated column masking disclosed historical data.'); }
    $rrFirst=$rrVisible['layout'][0]['children'][0]['children'][0];
    if ($rrFirst['uuid']!==$rpKey($rpText,$rpTwo)) { throw new RuntimeException('Historical row order changed.'); }
    if ($reader->read($rpForm,$rrSaved->id,2,'export')['values']!==[] || count($reader->read($rpForm,$rrSaved->id,1,'export',true)['values'])!==2) { throw new RuntimeException('Repeated export read bypassed field policy.'); }
    $rrAudit=$connection->rows('SELECT safe_metadata FROM '.$connection->table('audit_log').' WHERE submission_uuid = :uuid AND event_type = :event',[':uuid'=>$rrSaved->uuid,':event'=>'submission.reveal_sensitive']);
    if (count($rrAudit)!==1 || json_decode($rrAudit[0]['safe_metadata'],true)!==['fields'=>4,'request_metadata_items'=>0]) { throw new RuntimeException('Repeated sensitive audit leaked data or counted incorrectly.'); }
    $rrDenied=static function(callable $operation):void { try { $operation(); } catch(OutOfBoundsException) { return; } throw new RuntimeException('Invalid repeated download accepted.'); };
    $rrDenied(fn()=>$downloads->open($rpForm,$rrReceipt['uuid'],2));
    $rrDownload=$downloads->open($rpForm,$rrReceipt['uuid'],1);
    if (stream_get_contents($rrDownload->stream)!=='abc') { throw new RuntimeException('Repeated file bytes changed.'); } fclose($rrDownload->stream);
    $rrRow=$connection->row('SELECT * FROM '.$connection->table('submission_files').' WHERE uuid = :uuid',[':uuid'=>$rrReceipt['uuid']]);
    if (!Nicode\FormStudio\Submission\StoredFileAddress::matches($rrRow,$rrVisible['values']) || Nicode\FormStudio\Submission\StoredFileAddress::matches($rrRow,$rrMasked['values'])) { throw new RuntimeException('Attachment visibility escaped sensitive masking.'); }
    foreach ([$rpGroup.'/'.$rpTwo,$rpGroup.'/'.Nicode\FormStudio\Domain\Uuid::create()] as $wrongPath) {
        $connection->execute('UPDATE '.$connection->table('submission_files').' SET instance_path = :path, instance_hash = :hash WHERE uuid = :uuid',[':path'=>$wrongPath,':hash'=>hash('sha256',$wrongPath),':uuid'=>$rrReceipt['uuid']]);
        $rrDenied(fn()=>$downloads->open($rpForm,$rrReceipt['uuid'],1));
    }
    $connection->execute('UPDATE '.$connection->table('submission_files').' SET instance_path = :path, instance_hash = :hash WHERE uuid = :uuid',[':path'=>$rrRow['instance_path'],':hash'=>str_repeat('0',64),':uuid'=>$rrReceipt['uuid']]);
    $rrDenied(fn()=>$downloads->open($rpForm,$rrReceipt['uuid'],1));
} finally {
    $connection->execute('UPDATE '.$connection->table('submission_files').' SET instance_path = :path, instance_hash = :hash WHERE uuid = :uuid',[':path'=>$rpGroup.'/'.$rpOne,':hash'=>hash('sha256',$rpGroup.'/'.$rpOne),':uuid'=>$rrReceipt['uuid']]);
    $privateStorage->delete($rrObject->key);
}
echo "Repeated reads: historical row order, sensitive masking/reveal/audit, export field policy, private bytes and exact receipt scope passed.\n";
