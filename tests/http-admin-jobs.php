<?php
declare(strict_types=1);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/joomla-jobs.php'), $fixtureOutput, $fixtureExit);
if ($fixtureExit !== 0) { file_put_contents($root . '/build/job-fixture-error.log', implode("\n", $fixtureOutput)); throw new RuntimeException('Native jobs fixture preparation failed.'); }
$jobFixture = json_decode(file_get_contents($root . '/build/native-job-fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$jobApi = static function (string $task, ?array $post = null, array $query = [], int $expected = 200, bool $csrf = true) use ($base, $request, $token, $assert): array {
    $response = $request($base . '?' . http_build_query(['option' => 'com_nicode_form_studio', 'task' => 'job.' . $task] + $query), $post === null ? null : $post + ($csrf ? [$token => '1'] : []));
    $assert($response['status'] === $expected, 'Unexpected job status ' . $task . ': ' . $response['status']);
    $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    $assert(($data['ok'] ?? null) === ($expected === 200), 'Incorrect job envelope.');
    return $data['data'] ?? $data;
};
$awaitJob = static function (int $id) use ($jobApi): array {
    $deadline = microtime(true) + 55;
    for ($ticks = 0; $ticks <= 1000; $ticks++) {
        $record = $jobApi('record', query: ['id' => $id]);
        if ($record['state'] === 'completed') { return $record; }
        if (in_array($record['state'], ['failed', 'cancelled'], true)) { throw new RuntimeException('Native job reached terminal state: ' . $record['state']); }
        if (microtime(true) >= $deadline || $ticks === 1000) { throw new RuntimeException('Native job wait exhausted; state=' . $record['state'] . ', worker ticks=' . $ticks); }
        $jobApi('tick', []);
    }
    throw new LogicException('Unreachable job wait.');
};
$jobQuery = ['filters' => ['form_id' => $jobFixture['form_id']], 'fields' => [['field' => $jobFixture['field_uuid'], 'operator' => 'equals', 'value' => 'matching native export']]];
$jobInput = ['form_id' => $jobFixture['form_id'], 'type' => 'export-csv', 'fields' => [$jobFixture['field_uuid']], 'query' => $jobQuery];
$jobApi('enqueue', expected: 405);
$jobApi('enqueue', ['payload' => json_encode($jobInput)], expected: 403, csrf: false);
$jobApi('enqueue', ['payload' => json_encode(['form_id' => $jobFixture['form_id'], 'type' => 'file-cleanup'])], expected: 422);
$nativeJob = $jobApi('enqueue', ['payload' => json_encode($jobInput)])['id'];
$jobApi('tick', [], expected: 403, csrf: false);
$nativeJobStatus = $awaitJob($nativeJob);
$assert($nativeJobStatus['state'] === 'completed' && (int) $nativeJobStatus['processed'] === 2, 'Native filtered export failed.');
$assert(!isset($nativeJobStatus['parameters']) && !isset($nativeJobStatus['lease_token']), 'Native job internals disclosed.');
$downloadUrl = $base . '?option=com_nicode_form_studio&task=job.download';
$assert($request($downloadUrl, ['id' => $nativeJob])['status'] === 403, 'CSV download accepted missing CSRF.');
$csv = $request($downloadUrl, ['id' => $nativeJob, $token => '1']);
$assert($csv['status'] === 200 && str_contains(strtolower($csv['headers']), 'text/csv') && str_contains(strtolower($csv['headers']), 'no-store'), 'Native CSV download headers failed.');
$assert(substr_count($csv['body'], 'matching native export') === 2 && !str_contains($csv['body'], $jobFixture['responses'][2]), 'Native CSV ignored filter.');
$jsonInput=array_replace($jobInput,['type'=>'export-json']);
$jobApi('enqueue',['payload'=>json_encode($jsonInput)],expected:403,csrf:false);
$jsonJobId=$jobApi('enqueue',['payload'=>json_encode($jsonInput)])['id'];
$assert($request($downloadUrl,['id'=>$jsonJobId,$token=>'1'])['status']!==200,'Unfinished JSON artifact downloadable.');
$awaitJob($jsonJobId);
$assert($jobApi('record',query:['id'=>$jsonJobId])['state']==='completed','Native JSON job did not complete.');
$assert($request($downloadUrl,['id'=>$jsonJobId])['status']===403,'JSON download bypassed CSRF.');
$jsonDownload=$request($downloadUrl,['id'=>$jsonJobId,$token=>'1']);
$assert($jsonDownload['status']===200 && str_contains(strtolower($jsonDownload['headers']),'application/json') && str_contains($jsonDownload['headers'],'.json') && str_contains(strtolower($jsonDownload['headers']),'no-store'),'Native JSON download headers failed.');
$jsonRecords=json_decode($jsonDownload['body'],true,512,JSON_THROW_ON_ERROR);
$assert(count($jsonRecords)===2 && count(array_unique(array_column($jsonRecords,'reference')))===2,'Native JSON filtering or identity failed.');
foreach($jsonRecords as $jsonRecord) { $assert($jsonRecord['values']===[$jobFixture['field_uuid']=>'matching native export'] && is_int($jsonRecord['form_version_id']),'JSON values or snapshot identity changed.'); }
$jsonCancelled=$jobApi('enqueue',['payload'=>json_encode($jsonInput)])['id'];
$assert($jobApi('cancel',['id'=>$jsonCancelled])['cancelled'] && $request($downloadUrl,['id'=>$jsonCancelled,$token=>'1'])['status']!==200,'Cancelled JSON export downloadable.');
$jsonControls=$request($base.'?'.http_build_query(['option'=>'com_nicode_form_studio','view'=>'submissions','form_id'=>$jobFixture['form_id']]));
$assert(str_contains($jsonControls['body'],'data-nfs-enqueue="export-json"') && str_contains($jsonControls['body'],'Export JSON'),'Native JSON export control unavailable.');
$cancelId = $jobApi('enqueue', ['payload' => json_encode($jobInput)])['id'];
$assert($jobApi('cancel', ['id' => $cancelId])['cancelled'], 'Native cancellation failed.');
$assert($jobApi('record', query: ['id' => $cancelId])['state'] === 'cancelled', 'Native cancellation not persisted.');
$jobsPage = $request($base . '?option=com_nicode_form_studio&view=jobs');
require __DIR__ . '/http-admin-support-summary.php';
$assert(str_contains($healthPage['body'], 'Data-source cache') && (str_contains($healthPage['body'], 'cached only within each request') || str_contains($healthPage['body'], 'writes were not tested')), 'Native source cache diagnostic omitted its operating mode.');
$assert(str_contains($healthPage['body'], 'Required foreign keys and restrictive deletion rules'), 'Native foreign-key diagnostic was omitted or untranslated.');
$assert(str_contains($healthPage['body'], 'Column types, size, precision and nullability'), 'Native column type diagnostic was omitted or untranslated.');
$assert(str_contains($healthPage['body'], 'Required primary, unique and search indexes'), 'Native index diagnostic was omitted or untranslated.');
$assert(str_contains($healthPage['body'], 'Required tables and columns') && str_contains($healthPage['body'], 'types and indexes not checked'), 'Native schema structure diagnostic omitted its scope.');
$assert(str_contains($healthPage['body'], 'Mail configuration (delivery not tested)') && (str_contains($healthPage['body'], 'delivery remains untested') || str_contains($healthPage['body'], 'Joomla Global Configuration')), 'Native mail health has no translated diagnostic explanation.');
$assert(str_contains($healthPage['body'], 'PHP request and resource limits') && str_contains($healthPage['body'], '<code>max_input_vars</code>') && str_contains($healthPage['body'], '<code>post_max_size</code>') && str_contains($healthPage['body'], '<code>file_uploads</code>') && !preg_match('/>\s*COM_NICODE_FORM_STUDIO_VALUE\s*</', $healthPage['body']), 'Native request capacity diagnostics missing or untranslated.');
$logsPage = $request($base . '?option=com_nicode_form_studio&view=logs');
$assert($logsPage['status'] === 200 && str_contains($logsPage['body'], 'Technical log') && str_contains($logsPage['body'], 'compiler.failed'), 'Native technical log view or compiler event missing.');
$assert($healthPage['status'] === 200 && str_contains($healthPage['body'], 'System diagnostics') && str_contains($healthPage['body'], 'Private exports') && !preg_match('/>\s*COM_NICODE_FORM_STUDIO_HEALTH_/', $healthPage['body']) && !str_contains($healthPage['body'], 'native-private-exports'), 'Native safe health view failed.');
$configPage = $request($base . '?option=com_config&view=component&component=com_nicode_form_studio');
$assert($configPage['status'] === 200 && str_contains($configPage['body'], 'jform_export_path'), 'Native component options failed to render.');
$assert($jobsPage['status'] === 200 && str_contains($jobsPage['body'], 'data-nfs-job-tick'), 'Native jobs view failed.');
$responsesPage = $request($base . '?option=com_nicode_form_studio&view=submissions&form_id=' . $jobFixture['form_id']);
$assert($responsesPage['status'] === 200 && str_contains($responsesPage['body'], 'data-nfs-enqueue="export-csv"'), 'Native bulk controls failed.');
$retryDetail = $request($base . '?' . http_build_query(['option' => 'com_nicode_form_studio', 'view' => 'submission', 'form_id' => $jobFixture['form_id'], 'id' => $jobFixture['response_ids'][2]]));
$assert($retryDetail['status'] === 200 && str_contains($retryDetail['body'], 'data-nfs-retry'), 'Native retry controls missing.');
$retryInput = ['form_id' => $jobFixture['form_id'], 'id' => $jobFixture['response_ids'][2], 'attempts' => json_encode([$jobFixture['retry_action'] => 1])];
$retryUrl = $base . '?option=com_nicode_form_studio&task=submission.retry';
$assert($request($retryUrl, ['payload' => json_encode($retryInput)])['status'] === 403, 'Retry missing CSRF accepted.');
$retryResponse = $request($retryUrl, ['payload' => json_encode($retryInput), $token => '1']);
$assert($retryResponse['status'] === 200, 'Native retry enqueue failed.');
$retryJob = json_decode($retryResponse['body'], true, 512, JSON_THROW_ON_ERROR)['data']['job_id'];
$awaitJob($retryJob);
$assert($jobApi('record', query: ['id' => $retryJob])['state'] === 'completed', 'Native retry worker did not finish.');
$retried = $request($base . '?' . http_build_query(['option' => 'com_nicode_form_studio', 'task' => 'submission.record', 'form_id' => $jobFixture['form_id'], 'id' => $jobFixture['response_ids'][2]]));
$retryData = json_decode($retried['body'], true, 512, JSON_THROW_ON_ERROR)['data'];
$assert($retryData['action_status'] === 'succeeded' && (int) $retryData['actions'][0]['attempt'] === 2 && $retryData['actions'][0]['state'] === 'succeeded', 'Native retry did not persist action outcome.');
$assert($request($retryUrl, ['payload' => json_encode($retryInput), $token => '1'])['status'] === 403, 'Completed action was offered another retry.');
$runNativeJob = static function (array $input) use ($jobApi, $awaitJob): int {
    $id = $jobApi('enqueue', ['payload' => json_encode($input)])['id'];
    $awaitJob($id);
    return $id;
};
$csvFixture=json_decode(file_get_contents($root.'/build/native-csv-formula-fixture.json'),true,512,JSON_THROW_ON_ERROR);
$csvSafetyInput=['form_id'=>$csvFixture['form_id'],'type'=>'export-csv','fields'=>[$csvFixture['field_uuid']],'query'=>['filters'=>['form_id'=>$csvFixture['form_id']],'fields'=>[],'sort'=>'id_asc']];
$csvSafetyJob=$runNativeJob($csvSafetyInput);
$csvSafetyDownload=$request($downloadUrl,['id'=>$csvSafetyJob,$token=>'1']);
$assert($csvSafetyDownload['status']===200 && str_contains(strtolower($csvSafetyDownload['headers']),'text/csv'),'CSV formula fixture did not download as CSV.');
$csvSafetyStream=fopen('php://temp','w+b'); fwrite($csvSafetyStream,$csvSafetyDownload['body']); rewind($csvSafetyStream);
try {
    $assert(fgetcsv($csvSafetyStream,escape:'')===['reference','received_at','state',"'".$csvFixture['label']],'CSV header formula was not neutralized.');
    foreach($csvFixture['answers'] as $position=>$answer) {
        $row=fgetcsv($csvSafetyStream,escape:'');
        // First nine fixtures start with a dangerous prefix; the rest are literal controls.
        $expected=($position<9?"'":'').$answer['value'];
        $assert(is_array($row) && count($row)===4 && $row[0]===$answer['reference'] && $row[3]===$expected,'CSV download changed row shape/order or failed literal protection at '.$position.'.');
    }
    $assert(fgetcsv($csvSafetyStream,escape:'')===false,'CSV quoting introduced extra records.');
} finally { fclose($csvSafetyStream); }
// Protection belongs to the CSV representation, never the canonical response or JSON export.
$csvJsonJob=$runNativeJob(array_replace($csvSafetyInput,['type'=>'export-json']));
$csvJsonDownload=$request($downloadUrl,['id'=>$csvJsonJob,$token=>'1']);
$assert($csvJsonDownload['status']===200,'Formula fixture JSON control did not download.');
$csvJsonRecords=json_decode($csvJsonDownload['body'],true,512,JSON_THROW_ON_ERROR);
$assert(count($csvJsonRecords)===count($csvFixture['answers']),'Formula fixture JSON cardinality changed.');
foreach($csvFixture['answers'] as $position=>$answer) {
    $assert($csvJsonRecords[$position]['reference']===$answer['reference'] && $csvJsonRecords[$position]['values']===[$csvFixture['field_uuid']=>$answer['value']],'CSV escaping mutated canonical response data.');
}
file_put_contents($root.'/build/native-csv-formula-results.json',json_encode(['passed'=>true,'timestamp'=>gmdate(DATE_ATOM),'form_id'=>$csvFixture['form_id'],'rows'=>count($csvFixture['answers']),'header_protected'=>true,'csv_sha256'=>hash('sha256',$csvSafetyDownload['body']),'json_original_values'=>true],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo "Native CSV download: formula/control/BOM prefixes and header neutralized, quotes/commas/multiline/Unicode preserved, JSON originals unchanged.\n";
require __DIR__ . '/http-admin-bulk-states.php';
$privacyQuery = ['filters' => ['form_id' => $jobFixture['form_id'], 'state' => 'reviewed'], 'fields' => []];
$privacyJob = $runNativeJob(['form_id' => $jobFixture['form_id'], 'type' => 'submission-bulk', 'operation' => 'anonymize', 'query' => $privacyQuery]);
$assert((int) $jobApi('record', query: ['id' => $privacyJob])['processed'] === 2, 'Bulk anonymization ignored criteria.');
$assert($request($downloadUrl, ['id' => $nativeJob, $token => '1'])['status'] === 404, 'Privacy operation left old export downloadable.');
$deleteJob = $runNativeJob(['form_id' => $jobFixture['form_id'], 'type' => 'submission-bulk', 'operation' => 'delete', 'query' => $privacyQuery]);
$assert((int) $jobApi('record', query: ['id' => $deleteJob])['processed'] === 2, 'Bulk deletion ignored criteria.');
$runNativeJob(['form_id' => $jobFixture['form_id'], 'type' => 'reindex']);
file_put_contents($root . '/build/admin-jobs-http-results.json', json_encode(['passed' => true, 'timestamp' => gmdate(DATE_ATOM), 'job_id' => $nativeJob, 'checks' => ['native session and CSRF', 'private handler exclusion', 'filtered CSV worker composition', 'private streaming download', 'safe status', 'cancellation', 'jobs and bulk views', 'native component options', 'filtered bulk state/anonymize/delete', 'privacy export revocation', 'reindex endpoint', 'seeded local action retry', 'retry CSRF and completed-action exclusion']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Native jobs HTTP: filtered export, private CSV streaming, CSRF, safe status, cancellation and native views passed.\n";
