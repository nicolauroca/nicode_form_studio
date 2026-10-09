<?php
declare(strict_types=1);

// Internal snapshot only: authoring still rejects unfinished repeatable layouts.
$rpubForm=$dynamicForms->create('Repeated public services','repeated-public-'.bin2hex(random_bytes(6)),1);
$rpubData=$dynamicDraft; $rpubData['uuid']=$dynamicForms->draft($rpubForm)['uuid'];
$rpubGroup=Nicode\FormStudio\Domain\Uuid::create();
$rpubData['elements']=[['uuid'=>$rpubGroup,'type'=>'repeatable-group','repeat'=>['min'=>1,'max'=>2]],['uuid'=>$parent,'type'=>'field','parent_uuid'=>$rpubGroup],['uuid'=>$child,'type'=>'field','parent_uuid'=>$rpubGroup]];
$rpubData['fields'][0]['config']['default']='ES';
unset($rpubData['provider_dependencies']);
$rpubSpec=new Nicode\FormStudio\Domain\FormSpec($rpubData);
$rpubVersion=$connection->insert('form_versions',['form_id'=>$rpubForm,'revision'=>1,'schema_version'=>'1.0','spec'=>Nicode\FormStudio\Domain\CanonicalJson::encode($rpubData),'hash'=>$rpubSpec->hash,'published_at'=>gmdate('Y-m-d H:i:s'),'published_by'=>1,'comment'=>'Internal repeated public snapshot','revoked_at'=>null]);
$connection->execute('UPDATE '.$connection->table('forms')." SET state = 'published', published_version_id = :version, access = 1, language = '*' WHERE id = :id",[':version'=>$rpubVersion,':id'=>$rpubForm]);
$rpubRenderers=new Nicode\FormStudio\Rendering\FieldRendererRegistry();
foreach(['text','select'] as $type) { $rpubRenderers->register($type,new Nicode\FormStudio\Rendering\CoreFieldRenderer()); }
$rpubDisplay=new Nicode\FormStudio\Application\FormDisplay($dynamicForms,$registry,$dynamicRules,new Nicode\FormStudio\Rendering\FormRenderer($rpubRenderers,new Nicode\FormStudio\Rendering\PublicSpec($registry)),new Nicode\FormStudio\Security\PublicAccess(),$attemptTokens,$captchaFixture,$dynamicDependencies);
$rpubContexts=[$displayComponentContext,$displayModuleContext]; $rpubInitial=[];
foreach($rpubContexts as $rpubContext) {
    $html=$rpubDisplay->render($rpubForm,$rpubContext,'rpub-'.$rpubContext->channel,'/index.php','csrf_fixture')['html'];
    preg_match('/name="nfs_instances" value="([^"]+)"/',$html,$matches);
    $rows=json_decode(html_entity_decode($matches[1]??'',ENT_QUOTES|ENT_HTML5,'UTF-8'),true,512,JSON_THROW_ON_ERROR);
    if(count($rows[$rpubGroup]??[])!==1 || !str_contains($html,'value="ES"')) { throw new RuntimeException('Repeated display did not seed its minimum/default.'); }
    $rpubInitial[]=$rows;
    preg_match('/name="attempt" value="([^"]+)"/',$html,$matches); $token=$matches[1];
    $attemptTokens->verify($token,$rpubForm,$rpubVersion,'display-session:'.$rpubContext->channel);
    $row=$rows[$rpubGroup][0]; $key=$rpubGroup.'/'.$row.'/'.$parent;
    $retry=$rpubDisplay->render($rpubForm,$rpubContext,'rpub-retry','/index.php','csrf_fixture',submitted:[$key=>'FR'],errors:[$key=>['Check this row']],attempt:$token,declarations:$rows)['html'];
    if(!str_contains($retry,'value="FR"') || !str_contains($retry,'data-nfs-error-target="'.$key.'"') || !str_contains($retry,'name="attempt" value="'.$token.'"')) { throw new RuntimeException('Repeated retry lost values, addressed error or attempt.'); }
    preg_match('/name="nfs_instances" value="([^"]+)"/',$retry,$matches);
    if(json_decode(html_entity_decode($matches[1],ENT_QUOTES|ENT_HTML5,'UTF-8'),true)!==$rows) { throw new RuntimeException('Repeated redisplay replaced row identities.'); }
    try { $rpubDisplay->render($rpubForm,$rpubContext,'missing-rows','/index.php','csrf_fixture',submitted:[$key=>'FR']); throw new RuntimeException('Retry silently seeded new rows.'); } catch(InvalidArgumentException) {}
}
if($rpubInitial[0]===$rpubInitial[1]) { throw new RuntimeException('Independent renders reused row identities.'); }
$rpubRows=[$rpubGroup=>[$rpubInitial[0][$rpubGroup][0],$rpubInitial[1][$rpubGroup][0]]];
[$one,$two]=$rpubRows[$rpubGroup];
$rpubValues=[$rpubGroup.'/'.$one.'/'.$parent=>'ES',$rpubGroup.'/'.$two.'/'.$parent=>'FR'];
$rpubToken=$attemptTokens->issue($rpubForm,$rpubVersion,'display-session:component');
$rpubRequest=new Nicode\FormStudio\Submission\SubmitRequest($rpubForm,$rpubVersion,$rpubToken,$rpubValues);
$rpubEnvelope=new Nicode\FormStudio\Submission\RepeatedSubmitRequest($rpubRequest,new Nicode\FormStudio\Domain\RepeatedInstances($rpubData['elements'],$rpubRows));
$rpubOptions=$dynamicQueries->resolveInstances($rpubEnvelope,$displayComponentContext);
if($rpubOptions!==[$rpubGroup.'/'.$one.'/'.$child=>[['value'=>'MD','label'=>'Madrid','enabled'=>true]],$rpubGroup.'/'.$two.'/'.$child=>[]]) { throw new RuntimeException('Public options crossed row dependencies or leaked private data.'); }
try { $dynamicQueries->resolve($rpubRequest,$displayComponentContext); throw new RuntimeException('Options accepted omitted row declarations.'); } catch(InvalidArgumentException) {}
$rowChanges=new Nicode\FormStudio\Application\FormRows($dynamicForms,new Nicode\FormStudio\Security\PublicAccess(),$attemptTokens,new Nicode\FormStudio\Infrastructure\Database\RateLimiter($connection),$dynamicDependencies);
$rowRequest=static fn(array $rows,array $values=[]): Nicode\FormStudio\Submission\RepeatedSubmitRequest => new Nicode\FormStudio\Submission\RepeatedSubmitRequest(new Nicode\FormStudio\Submission\SubmitRequest($rpubForm,$rpubVersion,$rpubToken,$values),new Nicode\FormStudio\Domain\RepeatedInstances($rpubData['elements'],$rows));
$beforeRows=(int)$connection->row('SELECT COUNT(*) AS total FROM '.$connection->table('submissions').' WHERE form_id=:form',[':form'=>$rpubForm])['total'];
$oneRow=$rowRequest([$rpubGroup=>[$one]]);
$added=$rowChanges->change($oneRow,$displayComponentContext,'add',$rpubGroup);
$addedRow=$added['instances'][$rpubGroup][1];
if($added['instances'][$rpubGroup][0]!==$one || $addedRow===$one || !array_key_exists($rpubGroup.'/'.$one.'/'.$parent,$added['values']) || $added['values'][$rpubGroup.'/'.$one.'/'.$parent]!==null || array_key_exists($rpubGroup.'/'.$addedRow.'/'.$parent,$added['values'])) { throw new RuntimeException('Row addition lost empty sibling or initialized a new row as a retry.'); }
$rowHtml=$rpubDisplay->render($rpubForm,$displayComponentContext,'added-row','/index.php','csrf_fixture',submitted:$added['values'],attempt:$rpubToken,declarations:$added['instances'],reset:true)['html'];
$document=new DOMDocument(); $prior=libxml_use_internal_errors(true); $document->loadHTML($rowHtml); libxml_clear_errors(); libxml_use_internal_errors($prior); $xpath=new DOMXPath($document);
foreach([$one=>'', $addedRow=>'ES'] as $row=>$expected) {
    if($xpath->query('//input[@data-nfs-input="'.$rpubGroup.'/'.$row.'/'.$parent.'"]')->item(0)?->getAttribute('value')!==$expected) { throw new RuntimeException('Adding a row restored cleared sibling defaults.'); }
}
$both=$rowRequest($added['instances'],[$rpubGroup.'/'.$one.'/'.$parent=>'FR',$rpubGroup.'/'.$addedRow.'/'.$parent=>'ES']);
$forgedElements=$rpubData['elements']; $forgedElements[0]['repeat']['max']=3;
$forgedRows=[$rpubGroup=>[$one,$two,Nicode\FormStudio\Domain\Uuid::create()]];
$forgedEnvelope=new Nicode\FormStudio\Submission\RepeatedSubmitRequest($rpubRequest,new Nicode\FormStudio\Domain\RepeatedInstances($forgedElements,$forgedRows));
try { $rowChanges->change($forgedEnvelope,$displayComponentContext,'remove',$rpubGroup,$one); throw new RuntimeException('Row edit trusted a foreign envelope layout limit.'); } catch(InvalidArgumentException) {}
$staleEnvelope=new Nicode\FormStudio\Submission\RepeatedSubmitRequest(new Nicode\FormStudio\Submission\SubmitRequest($rpubForm,$rpubVersion+100000,$rpubToken,[]),$oneRow->instances);
try { $rowChanges->change($staleEnvelope,$displayComponentContext,'add',$rpubGroup); throw new RuntimeException('Row editing accepted a stale snapshot.'); } catch(OutOfBoundsException) {}
$removed=$rowChanges->change($both,$displayComponentContext,'remove',$rpubGroup,$one);
if($removed['instances']!==[$rpubGroup=>[$addedRow]] || isset($removed['values'][$rpubGroup.'/'.$one.'/'.$parent]) || $removed['values'][$rpubGroup.'/'.$addedRow.'/'.$parent]!=='ES') { throw new RuntimeException('Row removal changed retained sibling.'); }
foreach([[$both,'add',$rpubGroup,null],[$oneRow,'remove',$rpubGroup,$one],[$oneRow,'remove',$rpubGroup,$two],[$oneRow,'add',$parent,null],[$oneRow,'replace',$rpubGroup,null],[$oneRow,'add',$rpubGroup,$one]] as [$input,$operation,$group,$row]) {
    try { $rowChanges->change($input,$displayComponentContext,$operation,$group,$row); throw new RuntimeException('Invalid row mutation accepted.'); } catch(InvalidArgumentException) {}
}
foreach([
    new Nicode\FormStudio\Submission\RequestContext(0,[1],'en-GB','display-session',hash('sha256','row-csrf'),false),
    new Nicode\FormStudio\Submission\RequestContext(0,[1],'en-GB','other-session',hash('sha256','row-session'),true),
    $displayModuleContext,
] as $denied) {
    try { $rowChanges->change($oneRow,$denied,'add',$rpubGroup); throw new RuntimeException('Row mutation bypassed session/channel/CSRF.'); } catch(Nicode\FormStudio\Submission\SubmissionFailure $error) { if($error->category!=='session_error') { throw $error; } }
}
$connection->execute('UPDATE '.$connection->table('rate_limits').' SET attempts=120 WHERE scope_hash=:scope',[':scope'=>hash('sha256','rows:'.$rpubForm.':'.$displayComponentContext->rateScope)]);
try { $rowChanges->change($oneRow,$displayComponentContext,'add',$rpubGroup); throw new RuntimeException('Row editing bypassed rate limit.'); } catch(Nicode\FormStudio\Submission\SubmissionFailure $error) { if($error->category!=='rate_limited') { throw $error; } }
if((int)$connection->row('SELECT COUNT(*) AS total FROM '.$connection->table('submissions').' WHERE form_id=:form',[':form'=>$rpubForm])['total']!==$beforeRows) { throw new RuntimeException('Row editing stored a submission.'); }
$connection->execute('UPDATE '.$connection->table('forms')." SET state = 'unpublished' WHERE id = :id",[':id'=>$rpubForm]);
try { $dynamicQueries->resolveInstances($rpubEnvelope,$displayComponentContext); throw new RuntimeException('Repeated options bypassed unpublication.'); } catch(OutOfBoundsException) {}
try { $rowChanges->change($oneRow,$displayComponentContext,'add',$rpubGroup); throw new RuntimeException('Row editing bypassed unpublication.'); } catch(OutOfBoundsException) {}
echo "Repeated row service: identity/default isolation, selected removal, limits, operation membership, session/channel/CSRF, rate limit and no submission writes passed.\n";
echo "Repeated public services: component/module minimums, independent identities, retry values/errors/token and scoped remote options passed.\n";
