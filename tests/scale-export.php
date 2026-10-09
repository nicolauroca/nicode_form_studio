<?php
declare(strict_types=1);

// Existing synthetic million-response database only; never seed or alter answers.
$root=dirname(__DIR__); define('_JEXEC',1);
require $root.'/build/joomla-6.0.0/libraries/vendor/autoload.php';
require $root.'/src/lib_nicode_form_studio/autoload.php';
$format=$argv[1]??'json';
if(!in_array($format,['csv','json'],true)) throw new InvalidArgumentException('Choose csv or json.');
$config=json_decode(ltrim(file_get_contents($root.'/build/database-test.json'),"\xEF\xBB\xBF"),true,32,JSON_THROW_ON_ERROR);
if($config['host']!=='127.0.0.1' || $config['port']!==13367 || $config['database']!=='formstudio_test') throw new RuntimeException('Refusing non-test scale server.');
$driver=(new Joomla\Database\DatabaseFactory())->getDriver('mysql',['host'=>'127.0.0.1','port'=>13367,'user'=>$config['user'],'password'=>$config['password'],'database'=>'formstudio_scale','prefix'=>'scale_','charset'=>'utf8mb4']); $driver->connect();
$db=new Nicode\FormStudio\Infrastructure\Database\Connection($driver);
$source=new PDO('mysql:host=127.0.0.1;port=13367;dbname=formstudio_scale;charset=utf8mb4',$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::MYSQL_ATTR_USE_BUFFERED_QUERY=>false]);
if((int)$db->row('SELECT COUNT(*) AS total FROM '.$db->table('submissions'))['total']!==1000000) throw new RuntimeException('Expected existing million-response fixture.');
$formRows=$db->rows('SELECT id, alias, published_version_id FROM '.$db->table('forms').' ORDER BY id');
if(count($formRows)!==100) throw new RuntimeException('Expected exactly 100 synthetic forms.');
foreach($formRows as $form) if(!preg_match('/^scale-fixture-[0-9]+$/D',$form['alias'])) throw new RuntimeException('Unexpected scale form.');
$fields=new Nicode\FormStudio\Registry\FieldTypeRegistry(); Nicode\FormStudio\Field\CoreFieldTypes::register($fields);
$compiler=new Nicode\FormStudio\Compiler\FormCompiler($fields,new Nicode\FormStudio\Registry\ProviderRegistry(),new Nicode\FormStudio\Registry\ProviderRegistry(),new Nicode\FormStudio\Registry\ProviderRegistry());
$forms=new Nicode\FormStudio\Infrastructure\Database\FormRepository($db,$compiler);
$submissions=new Nicode\FormStudio\Infrastructure\Database\SubmissionRepository($db,new Nicode\FormStudio\Search\IndexProjector($fields),random_bytes(32));
$authorize=static fn(int $actor,?int $form,string $permission):bool=>$actor===1;
$reader=new Nicode\FormStudio\Application\SubmissionReader($submissions,$forms,$db,$authorize);
$jobs=new Nicode\FormStudio\Infrastructure\Database\JobRepository($db);
$search=new Nicode\FormStudio\Search\SqlSearchProvider($db,$fields,new Nicode\FormStudio\Search\CursorCodec(random_bytes(32)));
$directory=$root.'/build/scale-export-private'; if(!is_dir($directory)) mkdir($directory,0770,true);
$workspace=new Nicode\FormStudio\Export\ExportWorkspace($directory,$root.'/build/joomla-6.0.0');
$handler=new Nicode\FormStudio\Jobs\ExportHandler($db,$forms,$reader,$jobs,$workspace,$authorize,$search,format:$format);
$fieldIds=['0a4fbe36-0885-4269-82a0-4e5c15696d01','0a4fbe36-0885-4269-82a0-4e5c15696d02','0a4fbe36-0885-4269-82a0-4e5c15696d03'];
$results=[]; $totalRows=0; $totalBytes=0; $started=microtime(true); $exportSeconds=0;
foreach($formRows as $form) {
    $id=(int)$form['id']; $version=(int)$form['published_version_id'];
    $expectedRows=(int)$db->row('SELECT COUNT(*) AS total FROM '.$db->table('submissions').' WHERE form_id = :form',[':form'=>$id])['total'];
    $parameters=['form_id'=>$id,'version_id'=>$version,'fields'=>$fieldIds,'include_sensitive'=>false,'query'=>['filters'=>['form_id'=>$id],'fields'=>[],'sort'=>'id_asc'],'search_provider'=>['id'=>$search->id(),'version'=>$search->version()]];
    $jobId=$jobs->enqueue('export-'.$format,$parameters,1); $job=$jobs->get($jobId); $chunks=0; $begin=microtime(true);
    try {
        do {
            $db->execute('UPDATE '.$db->table('jobs')." SET available_at = '1000-01-01 00:00:00' WHERE id = :id",[':id'=>$jobId]);
            $lease=$jobs->claim(); if($lease?->id!==$jobId) throw new RuntimeException('Scale worker claimed another job.');
            $progress=$handler->run($lease,500); $jobs->checkpoint($lease,$progress); $chunks++;
            if($chunks%200===0) { echo $format.' form '.$id.': '.($chunks*500)." rows processed.\n"; flush(); }
        } while(!$progress->complete);
        $duration=microtime(true)-$begin; $exportSeconds+=$duration;
        $job=$jobs->get($jobId);
        if((int)$job['processed']!==$expectedRows || $job['state']!=='completed') throw new RuntimeException('Export job cardinality mismatch.');
        // Independent unbuffered source traversal, not the reader or export projection.
        $statement=$source->prepare('SELECT uuid, form_version_id, received_at, state, canonical_payload FROM scale_nicode_form_studio_submissions WHERE form_id = ? ORDER BY id'); $statement->execute([$id]);
        $digest=hash_init('sha256'); $seen=0; $temporary=fopen('php://temp','w+b');
        $csvHash=static function(array $row)use($digest,$temporary):void { ftruncate($temporary,0); rewind($temporary); fputcsv($temporary,$row,',','"','',"\r\n"); rewind($temporary); hash_update_stream($digest,$temporary); };
        if($format==='json') hash_update($digest,'['); else {
            $map=array_column($forms->version($id,$version)->toArray()['fields'],null,'uuid');
            $csvHash(['reference','received_at','state',...array_map(static fn($uuid)=>$map[$uuid]['config']['label']??$map[$uuid]['name'],$fieldIds)]);
        }
        while($row=$statement->fetch(PDO::FETCH_ASSOC)) {
            $values=json_decode($row['canonical_payload'],true,512,JSON_THROW_ON_ERROR)['values'];
            $selected=[]; foreach($fieldIds as $uuid) $selected[$uuid]=$values[$uuid];
            if($format==='json') {
                $record=['form_version_id'=>(int)$row['form_version_id'],'received_at'=>$row['received_at'],'reference'=>$row['uuid'],'state'=>$row['state'],'values'=>$selected];
                hash_update($digest,($seen===0?'':',').json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
            } else $csvHash([$row['uuid'],$row['received_at'],$row['state'],...array_values($selected)]);
            $seen++;
        }
        $statement->closeCursor(); fclose($temporary); if($format==='json') hash_update($digest,']');
        $stream=$workspace->open($job['uuid'],$format); $actual=hash_init('sha256'); $bytes=hash_update_stream($actual,$stream); fclose($stream);
        $expectedHash=hash_final($digest); $actualHash=hash_final($actual);
        if($seen!==$expectedRows || $actualHash!==$expectedHash) throw new RuntimeException('Export content/order differs from independent canonical source.');
        $results[]=['form_id'=>$id,'rows'=>$seen,'chunks'=>$chunks,'seconds'=>$duration,'bytes'=>$bytes,'sha256'=>$actualHash];
        $totalRows+=$seen; $totalBytes+=$bytes;
        echo $format.' verified '.$totalRows." / 1000000 rows.\n"; flush();
    } finally {
        $workspace->delete($job['uuid'],$format); $jobs->cancel($jobId);
        $db->execute('UPDATE '.$db->table('jobs')." SET artifact_key = NULL, result_code = 'artifact_expired', expires_at = :now WHERE id = :id",[':id'=>$jobId,':now'=>gmdate('Y-m-d H:i:s')]);
    }
}
if($totalRows!==1000000) throw new RuntimeException('Aggregate export omitted responses.');
$report=['passed'=>true,'format'=>$format,'rows'=>$totalRows,'forms'=>count($results),'largest_form_rows'=>max(array_column($results,'rows')),'bytes'=>$totalBytes,'export_seconds'=>$exportSeconds,'total_seconds'=>microtime(true)-$started,'php_peak_bytes'=>memory_get_peak_usage(true),'php_memory_limit'=>ini_get('memory_limit'),'database'=>$driver->getVersion(),'timestamp'=>gmdate(DATE_ATOM),'results'=>$results,'limitations'=>['Synthetic MariaDB fixture, not an installed HTTP throughput test.','One million rows across 100 scoped exports; largest single artifact has 500000 rows.','No concurrency or other-engine throughput claim.']];
file_put_contents($root.'/build/scale-export-'.$format.'-results.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo 'PASS '.json_encode(array_diff_key($report,['results'=>true]),JSON_THROW_ON_ERROR)."\n";
