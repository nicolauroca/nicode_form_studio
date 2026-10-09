<?php
declare(strict_types=1);

// Activate an internal fixture directly in the isolated DB; production compiler guard stays intact.
$rplData=$rsData; $rplData['actions']=[]; $rplData['elements'][0]['repeat']['min']=1;
$rplData['fields'][1]['config']=['required'=>true,'validation_messages'=>['required'=>'Number required here']];
$rplData['post_submit']=['messages'=>['success'=>'Default confirmation'],'conditional_messages'=>[['message'=>'Same row matched','condition'=>['group'=>'AND','children'=>[['field'=>$rsName,'operator'=>'equals','value'=>"A'"],['field'=>$rsNumber,'operator'=>'equals','value'=>20]]]]]];
$rplPipeline=new Nicode\FormStudio\Application\SubmissionPipeline($forms,$submissions,new Nicode\FormStudio\Security\PublicAccess(),$attemptTokens,$captchaFixture,new Nicode\FormStudio\Infrastructure\Database\RateLimiter($connection),$validationEngine,$pipelineActions,$postSubmit,$storageProviders);
$rplVersion=0;
foreach(['full','metadata','none'] as $offset=>$mode) {
    $rplData['persistence']['mode']=$mode; $rplSpec=new Nicode\FormStudio\Domain\FormSpec($rplData);
    $rplVersion=$connection->insert('form_versions',['form_id'=>$rsForm,'revision'=>3+$offset,'schema_version'=>'1.0','spec'=>Nicode\FormStudio\Domain\CanonicalJson::encode($rplData),'hash'=>$rplSpec->hash,'published_at'=>gmdate('Y-m-d H:i:s'),'published_by'=>1,'comment'=>'Internal pipeline snapshot','revoked_at'=>null]);
    $connection->execute('UPDATE '.$connection->table('forms')." SET state = 'published', published_version_id = :version, access = 1, language = '*' WHERE id = :id",[':version'=>$rplVersion,':id'=>$rsForm]);
    $raw=[$rsGroup.'/'.$rsOne.'/'.$rsName=>"A'",$rsGroup.'/'.$rsTwo.'/'.$rsName=>'B',$rsGroup.'/'.$rsOne.'/'.$rsNumber=>10,$rsGroup.'/'.$rsTwo.'/'.$rsNumber=>20];
    $rplToken=$attemptTokens->issue($rsForm,$rplVersion,'session-fixture:component');
    $rplRequest=new Nicode\FormStudio\Submission\SubmitRequest($rsForm,$rplVersion,$rplToken,$raw);
    $rplEnvelope=new Nicode\FormStudio\Submission\RepeatedSubmitRequest($rplRequest,new Nicode\FormStudio\Domain\RepeatedInstances($rplData['elements'],$rsRows));
    $captchaBefore=$captchaFixture->checks; $captchaFixture->valid=true;
    $result=$rplPipeline->submitInstances($rplEnvelope,$requestContext);
    if(!($result['accepted']??false) || $result['message']!=='Default confirmation' || $captchaFixture->checks!==$captchaBefore+1) { throw new RuntimeException('Repeated pipeline failed or crossed post-submit rows: '.json_encode($result)); }
    $again=$rplPipeline->submitInstances($rplEnvelope,$requestContext);
    if(!($again['replayed']??false) || $again['reference']!==$result['reference'] || $captchaFixture->checks!==$captchaBefore+1) { throw new RuntimeException('Repeated pipeline replay repeated CAPTCHA or changed identity.'); }
    $record=$connection->row('SELECT canonical_payload FROM '.$connection->table('submissions').' WHERE uuid = :uuid',[':uuid'=>$result['reference']]);
    if($mode==='none' && $record!==null) { throw new RuntimeException('Repeated no-store retained a row.'); }
    if($mode==='metadata' && json_decode($record['canonical_payload'],true)['instances']!==[]) { throw new RuntimeException('Repeated metadata retained row identities.'); }
    if($mode==='full' && json_decode($record['canonical_payload'],true)['instances']!==$rsRows) { throw new RuntimeException('Repeated pipeline lost declarations.'); }
    $changed=$raw; $changed[$rsGroup.'/'.$rsOne.'/'.$rsNumber]=20;
    $changedRequest=new Nicode\FormStudio\Submission\RepeatedSubmitRequest(new Nicode\FormStudio\Submission\SubmitRequest($rsForm,$rplVersion,$rplToken,$changed),$rplEnvelope->instances);
    if($rplPipeline->submitInstances($changedRequest,$requestContext)['category']!=='session_error') { throw new RuntimeException('Changed repeated retry accepted.'); }
    $newToken=$attemptTokens->issue($rsForm,$rplVersion,'session-fixture:component');
    $matched=new Nicode\FormStudio\Submission\RepeatedSubmitRequest(new Nicode\FormStudio\Submission\SubmitRequest($rsForm,$rplVersion,$newToken,$changed),$rplEnvelope->instances);
    if($rplPipeline->submitInstances($matched,$requestContext)['message']!=='Same row matched') { throw new RuntimeException('Repeated post-submit condition lost lexical scope.'); }
}
if($rplPipeline->submit($rplRequest,$requestContext)['category']!=='validation_error') { throw new RuntimeException('Ordinary pipeline accepted missing repeated envelope.'); }
if($rplPipeline->submitInstances($rplEnvelope,$noCsrf)['category']!=='session_error') { throw new RuntimeException('Repeated pipeline bypassed CSRF.'); }
$invalid=$raw; unset($invalid[$rsGroup.'/'.$rsOne.'/'.$rsNumber]);
$invalidEnvelope=new Nicode\FormStudio\Submission\RepeatedSubmitRequest(new Nicode\FormStudio\Submission\SubmitRequest($rsForm,$rplVersion,$attemptTokens->issue($rsForm,$rplVersion,'session-fixture:component'),$invalid),$rplEnvelope->instances);
$invalidResult=$rplPipeline->submitInstances($invalidEnvelope,$requestContext);
if($invalidResult['category']!=='validation_error' || ($invalidResult['error_messages'][$rsGroup.'/'.$rsOne.'/'.$rsNumber]['required']??null)!=='Number required here') { throw new RuntimeException('Repeated validation lost addressed custom errors.'); }
$captchaFixture->valid=false;
$fresh=new Nicode\FormStudio\Submission\RepeatedSubmitRequest(new Nicode\FormStudio\Submission\SubmitRequest($rsForm,$rplVersion,$attemptTokens->issue($rsForm,$rplVersion,'session-fixture:component'),$raw),$rplEnvelope->instances);
if($rplPipeline->submitInstances($fresh,$requestContext)['category']!=='captcha_error') { throw new RuntimeException('Repeated pipeline bypassed CAPTCHA.'); }
$captchaFixture->valid=true;
$connection->execute('UPDATE '.$connection->table('forms')." SET state = 'unpublished' WHERE id = :id",[':id'=>$rsForm]);
if($rplPipeline->submitInstances($rplEnvelope,$requestContext)['category']!=='form_unavailable') { throw new RuntimeException('Repeated replay bypassed unpublication.'); }
echo "Repeated pipeline: shared security, addressed validation/messages, scope-aware confirmation, persistence modes and CAPTCHA-safe replay passed.\n";
