<?php
declare(strict_types=1);

$jlForm=$forms->create('JSON privacy lifecycle','json-privacy-'.bin2hex(random_bytes(5)),1);
$jlDraft=$forms->draft($jlForm); $jlField=Nicode\FormStudio\Domain\Uuid::create();
$jlDraft['elements']=[['uuid'=>$jlField,'type'=>'field']];
$jlDraft['fields']=[['uuid'=>$jlField,'name'=>'answer','type'=>'text']];
$forms->saveDraft($jlForm,0,$jlDraft,1); $jlVersion=$forms->publish($jlForm,1,1); $jlSpec=$forms->version($jlForm,$jlVersion);
$jlSubmission=$submissions->persist($jlForm,$jlVersion,$jlSpec,[$jlField=>'Erase this JSON value'],hash('sha256',random_bytes(32)));
$jlParameters=['form_id'=>$jlForm,'version_id'=>$jlVersion,'fields'=>[$jlField]];
$jlFinished=$jobs->enqueue('export-json',$jlParameters,1); $jlLease=$jobs->claim();
if($jlLease?->id!==$jlFinished) { throw new RuntimeException('Unexpected JSON lifecycle lease.'); }
$jobs->checkpoint($jlLease,$jsonHandler->run($jlLease,10));
$jlActive=$jobs->enqueue('export-json',$jlParameters,1); $jlActiveLease=$jobs->claim();
if($jlActiveLease?->id!==$jlActive) { throw new RuntimeException('Unexpected active JSON lifecycle lease.'); }
$jsonHandler->run($jlActiveLease,1); // Bytes exist but the worker has not committed progress.
$jlMaintenance=new Nicode\FormStudio\Infrastructure\Database\SubmissionMaintenance($connection,$jobs,static fn()=>true);
$jlMaintenance->apply($jlForm,$jlSubmission->id,1,'anonymize');
foreach([$jlFinished,$jlActive] as $jlId) {
    if($jobs->get($jlId)['state']!=='cancelled') { throw new RuntimeException('Anonymization failed to revoke JSON job.'); }
    try { $exportDownloads->open($jlId,1); throw new RuntimeException('Anonymized JSON remains downloadable.'); } catch(OutOfBoundsException) {}
}
try { $jsonHandler->run($jlActiveLease,1); throw new RuntimeException('Revoked JSON worker retained artifact write authority.'); } catch(Nicode\FormStudio\Jobs\LeaseLost) {}
$jlCleanup=$jobs->claim();
if($jlCleanup?->type!=='export-cleanup') { throw new RuntimeException('JSON privacy did not schedule cleanup.'); }
$jlCleanupId=$jlCleanup->id;
do {
    $jobs->checkpoint($jlCleanup,$jsonCleanup->run($jlCleanup,50));
    if($jobs->get($jlCleanupId)['state']==='completed') break;
    $jlCleanup=$jobs->claim();
    if($jlCleanup?->id!==$jlCleanupId) { throw new RuntimeException('JSON privacy cleanup lost its job.'); }
} while(true);
foreach([$jlFinished,$jlActive] as $jlId) {
    $jlJob=$jobs->get($jlId);
    if($jlJob['artifact_key']!==null || is_file($exportRoot.'/'.$jlJob['uuid'].'.json')) { throw new RuntimeException('JSON private bytes survived revocation cleanup.'); }
}
echo "JSON privacy lifecycle: completed and uncheckpointed exports revoked, stale worker fenced, private artifacts removed.\n";
