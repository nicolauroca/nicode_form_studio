<?php
declare(strict_types=1);

// All preceding job assertions are finished; isolate this final lease scenario.
$connection->execute('UPDATE '.$connection->table('jobs')." SET state = 'cancelled', lease_token = NULL, lease_until = NULL, revision = revision + 1 WHERE state IN ('pending', 'retryable', 'running')");
foreach ([false,true] as $includeSensitive) {
    $rxActor=$includeSensitive?1:2;
    $rxId=$jobs->enqueue('export-csv',['form_id'=>$rpForm,'version_id'=>$rpVersion,'fields'=>[$rpText],'include_sensitive'=>$includeSensitive],$rxActor);
    $rxLease=$jobs->claim();
    if ($rxLease?->id!==$rxId) { throw new RuntimeException('Unexpected repeated export lease.'); }
    $exportHandler->run($rxLease,1); // Crash after bytes, before checkpoint.
    $rxProgress=$exportHandler->run($rxLease,1); $jobs->checkpoint($rxLease,$rxProgress);
    for($chunk=0;$chunk<10 && $jobs->get($rxId)['state']!=='completed';$chunk++) {
        $rxLease=$jobs->claim();
        if ($rxLease?->id!==$rxId) { throw new RuntimeException('Repeated export lost resumable lease.'); }
        $jobs->checkpoint($rxLease,$exportHandler->run($rxLease,1));
    }
    $rxJob=$jobs->get($rxId);
    if($rxJob['state']!=='completed' || (int)$rxJob['processed']!==3) { throw new RuntimeException('Repeated export did not complete once per response.'); }
    $rxDownload=$exportDownloads->open($rxId,$rxActor); $rxRows=[];
    while(($row=fgetcsv($rxDownload->stream,escape:''))!==false) { $rxRows[]=$row; } fclose($rxDownload->stream);
    if(count($rxRows)!==4 || $rxRows[0]!==['reference','received_at','state','text']) { throw new RuntimeException('Repeated CSV changed stable columns or duplicated rows.'); }
    $rxCells=array_column(array_slice($rxRows,1),3,0);
    $rxExpected=[['instance_path'=>$rpGroup.'/'.$rpTwo,'value'=>'Dos'],['instance_path'=>$rpGroup.'/'.$rpOne,'value'=>'Uno ñ']];
    if(json_decode($rxCells[$rpSaved->uuid],true,flags:JSON_THROW_ON_ERROR)!==$rxExpected) { throw new RuntimeException('Repeated CSV lost scope order or values.'); }
    if($includeSensitive) {
        if(json_decode($rxCells[$rrSaved->uuid],true,flags:JSON_THROW_ON_ERROR)!==$rxExpected) { throw new RuntimeException('Authorized historical sensitive export lost repeated values.'); }
    } elseif($rxCells[$rrSaved->uuid]!=='') { throw new RuntimeException('Repeated export disclosed historical sensitive values.'); }
    $rxOther=array_diff_key($rxCells,array_flip([$rpSaved->uuid,$rrSaved->uuid]));
    if(array_values($rxOther)!==['']) { throw new RuntimeException('Metadata-only repeated export retained values.'); }
    $exportWorkspace->delete($rxJob['uuid']);
}
echo "Repeated CSV: stable columns, ordered scope/value JSON, historical sensitive policy, metadata omission and checkpoint replay passed.\n";

$jsonHandler=new Nicode\FormStudio\Jobs\ExportHandler($connection,$forms,$reader,$jobs,$exportWorkspace,$authorizeRead,$search,format:'json');
foreach([false,true] as $includeSensitive) {
    $actor=$includeSensitive?1:2;
    $jsonId=$jobs->enqueue('export-json',['form_id'=>$rpForm,'version_id'=>$rpVersion,'fields'=>[$rpText],'include_sensitive'=>$includeSensitive],$actor);
    $jsonLease=$jobs->claim();
    if($jsonLease?->id!==$jsonId) { throw new RuntimeException('Unexpected JSON lease.'); }
    $jsonHandler->run($jsonLease,1); // Abandon bytes before checkpoint, then replay.
    $jobs->checkpoint($jsonLease,$jsonHandler->run($jsonLease,1));
    for($chunk=0;$chunk<10 && $jobs->get($jsonId)['state']!=='completed';$chunk++) {
        $jsonLease=$jobs->claim();
        if($jsonLease?->id!==$jsonId) { throw new RuntimeException('JSON lease escaped its job.'); }
        $jobs->checkpoint($jsonLease,$jsonHandler->run($jsonLease,1));
    }
    $jsonJob=$jobs->get($jsonId);
    if($jsonJob['state']!=='completed' || (int)$jsonJob['processed']!==3) { throw new RuntimeException('JSON export did not complete in chunks.'); }
    $download=$exportDownloads->open($jsonId,$actor);
    if($download->headers['Content-Type']!=='application/json; charset=utf-8' || !str_ends_with($download->headers['Content-Disposition'],'.json"')) { throw new RuntimeException('JSON download headers incorrect.'); }
    $jsonRows=json_decode(stream_get_contents($download->stream),true,512,JSON_THROW_ON_ERROR); fclose($download->stream);
    $jsonById=array_column($jsonRows,null,'reference');
    if(count($jsonRows)!==3 || count($jsonById)!==3 || $jsonById[$rpSaved->uuid]['values'][$rpText]!==$rxExpected || $jsonById[$rpSaved->uuid]['form_version_id']!==$rpVersion) { throw new RuntimeException('JSON export lost historical version, types, order or row identity.'); }
    if($jsonById[$rrSaved->uuid]['values'][$rpText]!==($includeSensitive?$rxExpected:null)) { throw new RuntimeException('JSON export violated historical sensitive policy.'); }
    foreach($jsonRows as $row) { if(isset($row['request_metadata']) || array_keys($row['values'])!==[$rpText]) { throw new RuntimeException('JSON export disclosed unselected data.'); } }
    try { $exportDownloads->open($jsonId,99); throw new RuntimeException('JSON foreign download allowed.'); } catch(OutOfBoundsException) {}
    $connection->execute('UPDATE '.$connection->table('jobs').' SET expires_at = :past WHERE id = :id',[':past'=>'2000-01-01 00:00:00',':id'=>$jsonId]);
    try { $exportDownloads->open($jsonId,$actor); throw new RuntimeException('Expired JSON download allowed.'); } catch(OutOfBoundsException) {}
    $cleanupId=$jobs->enqueue('export-cleanup',[],1);
    $jsonCleanup=new Nicode\FormStudio\Jobs\ExportCleanupHandler($connection,$jobs,$exportWorkspace);
    for($chunk=0;$chunk<1000 && $jobs->get($cleanupId)['state']!=='completed';$chunk++) {
        $jsonCleanupLease=$jobs->claim();
        if($jsonCleanupLease?->id!==$cleanupId) { throw new RuntimeException('Unexpected JSON cleanup lease.'); }
        $jobs->checkpoint($jsonCleanupLease,$jsonCleanup->run($jsonCleanupLease,50));
    }
    if($jobs->get($jsonId)['artifact_key']!==null || is_file($exportRoot.'/'.$jsonJob['uuid'].'.json')) { throw new RuntimeException('JSON artifact survived expiration cleanup.'); }
}
echo "Repeated JSON: chunk replay, canonical types/scopes, historical privacy, download ownership/MIME and expiration cleanup passed.\n";
