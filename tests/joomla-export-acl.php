<?php
declare(strict_types=1);

// Included by the guarded native ACL suite; component rules are restored by its finally.
$exForm=$administration->create('Native export ACL','export-acl-'.$suffix,(int)$admin->id);
$exDraft=$forms->draft($exForm); $exField=Nicode\FormStudio\Domain\Uuid::create(); $exSecret=Nicode\FormStudio\Domain\Uuid::create();
$exDraft['elements']=[['uuid'=>$exField,'type'=>'field'],['uuid'=>$exSecret,'type'=>'field']];
$exDraft['fields']=[['uuid'=>$exField,'name'=>'answer','type'=>'text'],['uuid'=>$exSecret,'name'=>'secret','type'=>'text','sensitive'=>true,'include_export'=>true]];
$exRevision=$administration->save($exForm,0,$exDraft,(int)$admin->id); $exVersion=$administration->publish($exForm,$exRevision,(int)$admin->id);
$responses->persist($exForm,$exVersion,$forms->version($exForm,$exVersion),[$exField=>'Public synthetic answer',$exSecret=>'Private synthetic answer'],hash('sha256',random_bytes(32)));
$exAsset=new Joomla\CMS\Table\Asset($database,$events); $exAsset->loadByName('com_nicode_form_studio.form.'.$exForm);
$exSet=static function(bool $export,bool $sensitive=false,bool $manage=false)use($exAsset):void {
    $exAsset->rules=json_encode(['formstudio.submissions.view'=>['2'=>0],'formstudio.submissions.export'=>['2'=>(int)$export],'formstudio.submissions.view_sensitive'=>['2'=>(int)$sensitive],'formstudio.jobs.manage'=>['2'=>(int)$manage]],JSON_THROW_ON_ERROR);
    if(!$exAsset->store()) throw new RuntimeException('Export ACL fixture could not save rules.');
    Joomla\CMS\Access\Access::clearStatics();
};
$exOther=new Joomla\CMS\User\User(); $exPassword=bin2hex(random_bytes(24));
$exOtherData=['name'=>'Export ACL second actor','username'=>'export-other-'.$suffix,'email'=>'export-other-'.$suffix.'@example.test','password'=>$exPassword,'password2'=>$exPassword,'groups'=>[2],'block'=>0];
if(!$exOther->bind($exOtherData) || !$exOther->save()) throw new RuntimeException('Export ACL second actor unavailable.');
$exRoot=$root.'/build/native-export-acl'; if(!is_dir($exRoot)) mkdir($exRoot,0770,true);
$exWorkspace=new Nicode\FormStudio\Export\ExportWorkspace($exRoot,$site);
$exJobs=new Nicode\FormStudio\Infrastructure\Database\JobRepository($db);
$exRegistry=new Nicode\FormStudio\Registry\JobHandlerRegistry();
foreach(['csv','json'] as $exFormat) $exRegistry->register(new Nicode\FormStudio\Jobs\ExportHandler($db,$forms,$responseReader,$exJobs,$exWorkspace,$authorization->allows(...),$responseSearch,format:$exFormat));
$exAdmin=new Nicode\FormStudio\Application\JobAdministration($db,$forms,$exJobs,$exRegistry,$responseSearch,$authorization->allows(...));
$exDownloads=new Nicode\FormStudio\Application\ExportDownloads($exJobs,$exWorkspace,$authorization->allows(...));
$exDeny=static function(string $class,callable $operation):void { try { $operation(); } catch(Throwable $error) { if($error instanceof $class) return; throw $error; } throw new RuntimeException('Native export ACL unexpectedly allowed operation.'); };
$exActor=(int)$visitor->id; $exArtifactIds=[];
try {
    foreach(['csv','json'] as $exFormat) {
        $exType='export-'.$exFormat;
        $exDeny(DomainException::class,fn()=>$exAdmin->enqueue(0,$exForm,$exType,['fields'=>[$exField]]));
        $exSet(false);
        $exDeny(DomainException::class,fn()=>$exAdmin->enqueue($exActor,$exForm,$exType,['fields'=>[$exField]]));
        $exSet(true);
        if($authorization->allows($exActor,$exForm,'formstudio.submissions.view')) throw new RuntimeException('Export test accidentally granted response viewing.');
        $exDeny(DomainException::class,fn()=>$exAdmin->enqueue($exActor,$exForm,$exType,['fields'=>[$exSecret],'include_sensitive'=>true]));
        foreach([false,true] as $exSensitive) {
            $exSet(true,$exSensitive);
            if($exSensitive) { $exDeny(DomainException::class,fn()=>$exAdmin->enqueue($exActor,$exForm,$exType,['fields'=>[$exSecret]])); }
            $exId=$exAdmin->enqueue($exActor,$exForm,$exType,['fields'=>[$exSensitive?$exSecret:$exField],'include_sensitive'=>$exSensitive]);
            $exArtifactIds[$exId]=$exFormat;
            $db->execute('UPDATE '.$db->table('jobs')." SET available_at = '1000-01-01 00:00:00' WHERE id = :id",[':id'=>$exId]);
            $exLease=$exJobs->claim(); if($exLease?->id!==$exId) throw new RuntimeException('Native export ACL claimed a foreign fixture job.');
            $exHandler=$exRegistry->get($exType);
            $exSet(false,$exSensitive);
            $exDeny(DomainException::class,fn()=>$exHandler->run($exLease,10));
            if(is_file($exRoot.'/'.$exLease->uuid.'.'.$exFormat)) throw new RuntimeException('Revoked exporter wrote private bytes.');
            if($exSensitive) { $exSet(true,false); $exDeny(DomainException::class,fn()=>$exHandler->run($exLease,10)); }
            $exSet(true,$exSensitive);
            $exJobs->checkpoint($exLease,$exHandler->run($exLease,10));
            $exDownload=$exDownloads->open($exId,$exActor); $exBytes=stream_get_contents($exDownload->stream); fclose($exDownload->stream);
            if(!str_contains($exBytes,$exSensitive?'Private synthetic answer':'Public synthetic answer')) throw new RuntimeException('Authorized export lost selected value.');
            $exDeny(OutOfBoundsException::class,fn()=>$exDownloads->open($exId,(int)$exOther->id));
            $exSet(true,$exSensitive,true);
            $exDownload=$exDownloads->open($exId,(int)$exOther->id); fclose($exDownload->stream);
            $exSet(false,$exSensitive,true);
            $exDeny(OutOfBoundsException::class,fn()=>$exDownloads->open($exId,$exActor));
            $exDeny(OutOfBoundsException::class,fn()=>$exDownloads->open($exId,(int)$exOther->id));
            if($exSensitive) {
                $exSet(true,false,true);
                $exDeny(OutOfBoundsException::class,fn()=>$exDownloads->open($exId,(int)$exOther->id));
                $exDeny(OutOfBoundsException::class,fn()=>$exDownloads->open($exId,$exActor));
            }
            $exSet(true,$exSensitive);
        }
    }
} finally {
    foreach($exArtifactIds as $exId=>$exFormat) {
        $exWorkspace->delete($exJobs->get($exId)['uuid'],$exFormat); $exJobs->cancel($exId);
        $db->execute('UPDATE '.$db->table('jobs')." SET artifact_key = NULL, result_code = 'artifact_expired', expires_at = :now WHERE id = :id",[':id'=>$exId,':now'=>gmdate('Y-m-d H:i:s')]);
    }
    $exSet(false);
}
file_put_contents($root.'/build/native-export-acl-results.json',json_encode(['passed'=>true,'formats'=>['csv','json'],'export_without_view'=>true,'sensitive_permission'=>true,'foreign_owner_and_manager'=>true,'revocation_before_write_and_download'=>true,'timestamp'=>gmdate(DATE_ATOM)],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo "Native CSV/JSON ACL: export independent of view, sensitive opt-in, owner/manager boundaries and permission revocation before write/download passed.\n";
