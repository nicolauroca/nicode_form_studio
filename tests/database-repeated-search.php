<?php
declare(strict_types=1);

$rsForm=$forms->create('Repeated search','repeated-search-'.bin2hex(random_bytes(5)),1);
$rsData=$forms->draft($rsForm);
[$rsGroup,$rsName,$rsNumber,$rsOne,$rsTwo]=array_map(static fn()=>Nicode\FormStudio\Domain\Uuid::create(),range(1,5));
$rsData['elements']=[['uuid'=>$rsGroup,'type'=>'repeatable-group','repeat'=>['min'=>0,'max'=>2]],['uuid'=>$rsName,'type'=>'field','parent_uuid'=>$rsGroup],['uuid'=>$rsNumber,'type'=>'field','parent_uuid'=>$rsGroup]];
$rsData['fields']=[['uuid'=>$rsName,'name'=>'person','type'=>'text','index'=>true,'config'=>['max_length'=>255]],['uuid'=>$rsNumber,'name'=>'number','type'=>'integer','index'=>true]];
$rsSpec=new Nicode\FormStudio\Domain\FormSpec($rsData);
$rsVersion=$connection->insert('form_versions',['form_id'=>$rsForm,'revision'=>1,'schema_version'=>'1.0','spec'=>Nicode\FormStudio\Domain\CanonicalJson::encode($rsData),'hash'=>$rsSpec->hash,'published_at'=>gmdate('Y-m-d H:i:s'),'published_by'=>1,'comment'=>'Internal search snapshot','revoked_at'=>null]);
foreach([$rsName,$rsNumber] as $field) { $connection->insert('version_field_policy',['form_id'=>$rsForm,'form_version_id'=>$rsVersion,'field_uuid'=>$field,'sensitive'=>0,'indexed'=>1]); }
$rsRows=[$rsGroup=>[$rsOne,$rsTwo]]; $rsSaved=[];
foreach([[10,20],[20,10],[20,20],[null,20]] as $pair) {
    $raw=[$rsGroup.'/'.$rsOne.'/'.$rsName=>"A'",$rsGroup.'/'.$rsTwo.'/'.$rsName=>'B',$rsGroup.'/'.$rsOne.'/'.$rsNumber=>$pair[0],$rsGroup.'/'.$rsTwo.'/'.$rsNumber=>$pair[1]];
    $valid=$validationEngine->validateInstances($rsSpec,$rsRows,$raw);
    $rsSaved[]=$submissions->persistInstances($rsForm,$rsVersion,$rsSpec,$rsRows,$valid,hash('sha256',random_bytes(32)));
}
$rsScope=new Nicode\FormStudio\Search\SearchScope([$rsForm=>false]);
$rsFilters=[['field'=>$rsName,'operator'=>'equals','value'=>"A'",'same_instance'=>'person'],['field'=>$rsNumber,'operator'=>'equals','value'=>20,'same_instance'=>'person']];
$rsQuery=static fn(array $filters,int $limit=50,?string $cursor=null)=>new Nicode\FormStudio\Search\SearchRequest(['form_id'=>$rsForm],$filters,$limit,$cursor,sort:'id_asc');
$rsIds=static fn($page)=>array_map('intval',array_column($page->rows,'id'));
if($rsIds($search->search($rsQuery($rsFilters),$rsScope,$rsSpec))!==[$rsSaved[1]->id,$rsSaved[2]->id]) { throw new RuntimeException('Correlated search crossed repeated rows.'); }
$rsIndependent=array_map(static function(array $filter):array { unset($filter['same_instance']); return $filter; },$rsFilters);
if(count($search->search($rsQuery($rsIndependent),$rsScope,$rsSpec)->rows)!==4) { throw new RuntimeException('Independent repeated predicates changed meaning.'); }
$rsNegative=$rsFilters; $rsNegative[1]['operator']='not_equals';
if($rsIds($search->search($rsQuery($rsNegative),$rsScope,$rsSpec))!==[$rsSaved[0]->id,$rsSaved[3]->id]) { throw new RuntimeException('Correlated negation did not stay within its anchored row.'); }
// Equal hashes never substitute for exact scope equality.
$connection->execute('UPDATE '.$connection->table('submission_index').' SET ordinal = 1, instance_hash = :hash WHERE submission_id = :id AND field_uuid = :field AND instance_path = :path',[':hash'=>hash('sha256',$rsGroup.'/'.$rsTwo),':id'=>$rsSaved[0]->id,':field'=>$rsName,':path'=>$rsGroup.'/'.$rsOne]);
try {
    if($rsIds($search->search($rsQuery($rsFilters),$rsScope,$rsSpec))!==[$rsSaved[1]->id,$rsSaved[2]->id]) { throw new RuntimeException('Correlated search trusted a colliding hash.'); }
} finally { $submissions->reindex($rsForm,$rsSaved[0]->id,$rsSpec); }
$rsPage=$search->search($rsQuery($rsFilters,1),$rsScope,$rsSpec);
if($rsPage->nextCursor===null || $rsIds($search->search($rsQuery($rsFilters,1,$rsPage->nextCursor),$rsScope,$rsSpec))!==[$rsSaved[2]->id]) { throw new RuntimeException('Correlated keyset continuation failed.'); }
$rpReject(fn()=>$search->search($rsQuery($rsIndependent,1,$rsPage->nextCursor),$rsScope,$rsSpec));
$rpReject(fn()=>$search->search($rsQuery([$rsNegative[1]]),$rsScope,$rsSpec));
$invalid=$rsFilters; $invalid[0]['same_instance']='x); DROP';
$rpReject(fn()=>$search->search($rsQuery($invalid),$rsScope,$rsSpec));
$different=$rsData; $different['elements'][2]['parent_uuid']=null;
$rpReject(fn()=>$search->search($rsQuery($rsFilters),$rsScope,new Nicode\FormStudio\Domain\FormSpec($different)));
// Historical privacy applies to every member, including negative conditions.
$connection->execute('UPDATE '.$connection->table('version_field_policy').' SET '.$connection->quote('sensitive').' = 1 WHERE form_version_id = :version AND field_uuid = :field',[':version'=>$rsVersion,':field'=>$rsNumber]);
try {
    foreach([$rsFilters,$rsNegative] as $filters) { if($search->search($rsQuery($filters),$rsScope,$rsSpec)->rows!==[]) { throw new RuntimeException('Correlated filter leaked historical sensitive data.'); } }
    if(count($search->search($rsQuery($rsFilters),new Nicode\FormStudio\Search\SearchScope([$rsForm=>true]),$rsSpec)->rows)!==2) { throw new RuntimeException('Authorized historical correlation failed.'); }
} finally { $connection->execute('UPDATE '.$connection->table('version_field_policy').' SET '.$connection->quote('sensitive').' = 0 WHERE form_version_id = :version AND field_uuid = :field',[':version'=>$rsVersion,':field'=>$rsNumber]); }
echo "Repeated SQL search: same-row positives/negation, independent filters, hash collision isolation, cursor binding, scope validation and historical privacy passed.\n";
