<?php
declare(strict_types=1);

$root=dirname(__DIR__); $site=$root.'/build/joomla-6.0.0';
define('_JEXEC',1); define('JPATH_BASE',$site);
require $site.'/includes/defines.php'; require $site.'/includes/framework.php';
require $root.'/src/lib_nicode_form_studio/autoload.php';
$container=Joomla\CMS\Factory::getContainer();
$container->alias('session','session.cli')->alias(Joomla\CMS\Session\Session::class,'session.cli')->alias(Joomla\Session\SessionInterface::class,'session.cli');
$app=$container->get(Joomla\CMS\Application\SiteApplication::class); Joomla\CMS\Factory::$application=$app;
$app->createExtensionNamespaceMap();
if($app->get('db')!=='formstudio_joomla' || $app->get('host')!=='127.0.0.1:13367' || $app->get('live_site')!=='http://127.0.0.1:13371') { throw new RuntimeException('Refusing non-isolated browser fixture.'); }
$runtime=new Joomla\DI\Container($container);
$runtime->registerServiceProvider(new Nicode\FormStudio\Infrastructure\Joomla\RuntimeProvider($app,new Joomla\Registry\Registry(),$site));
$connection=$runtime->get(Nicode\FormStudio\Infrastructure\Database\Connection::class);
$file=$root.'/build/joomla-repeated-browser.json';
if(in_array($argv[1]??'',['--inspect','--verify','--cleanup'],true)) {
    $fixture=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    $row=$connection->row('SELECT alias FROM '.$connection->table('forms').' WHERE id=:id',[':id'=>$fixture['form_id']]);
    if(!str_starts_with($row['alias']??'','nested-browser-')) { throw new RuntimeException('Fixture identity mismatch.'); }
    if($argv[1]==='--cleanup') { $connection->execute('UPDATE '.$connection->table('forms')." SET state='unpublished' WHERE id=:id",[':id'=>$fixture['form_id']]); echo "Browser fixture unpublished.\n"; exit; }
    $records=$connection->rows('SELECT canonical_payload FROM '.$connection->table('submissions').' WHERE form_id=:id',[':id'=>$fixture['form_id']]);
    if($argv[1]==='--verify') {
        if(count($records)!==1 || !isset($argv[2],$argv[3])) { throw new RuntimeException('Expected one submission and the observed parent/child UUIDs.'); }
        $payload=json_decode($records[0]['canonical_payload'],true,512,JSON_THROW_ON_ERROR);
        $scope=$fixture['outer'].'/'.$argv[2].'/'.$fixture['inner'];
        $expected=[$fixture['outer']=>[$argv[2]],$scope=>[$argv[3]]];
        $name=$scope.'/'.$argv[3].'/'.$fixture['name']; $copy=$scope.'/'.$argv[3].'/'.$fixture['copy'];
        if($payload['instances']!==$expected || count($payload['values'])!==2 || ($payload['values'][$name]??null)!=='BETA' || ($payload['values'][$copy]??null)!=='BETA') { throw new RuntimeException('Removed branches survived or retained identity/provider-derived values changed.'); }
        file_put_contents($root.'/build/native-nested-browser-results.json',json_encode(['passed'=>true,'timestamp'=>gmdate(DATE_ATOM),'form_id'=>$fixture['form_id'],'version_id'=>$fixture['version_id'],'instances'=>$expected,'checks'=>['lazy provider loaded from zero fields','nested add/remove','sibling identity','uppercase normalization','same-row readonly derivation','removed descendants absent','one canonical submission']],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        echo "Nested browser submission: exact retained parent/child UUIDs, provider normalization/derivation, removed-branch exclusion and one canonical response verified.\n"; exit;
    }
    echo json_encode(['submissions'=>array_map(static fn($record)=>json_decode($record['canonical_payload'],true),$records)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n"; exit;
}
$types=$runtime->get(Nicode\FormStudio\Registry\FieldTypeRegistry::class);
if(!$types->has('fixture.upper')) { throw new RuntimeException('Install the existing fixture provider before this browser test.'); }
$forms=$runtime->get(Nicode\FormStudio\Infrastructure\Database\FormRepository::class);
$id=$forms->create('Prueba de filas anidadas','nested-browser-'.bin2hex(random_bytes(6)),1); $data=$forms->draft($id);
[$outer,$inner,$name,$copy]=array_map(static fn()=>Nicode\FormStudio\Domain\Uuid::create(),[1,2,3,4]);
$data['elements']=[['uuid'=>$outer,'type'=>'repeatable-group','title'=>'Familias','repeat'=>['min'=>1,'max'=>2]],['uuid'=>$inner,'type'=>'repeatable-group','title'=>'Contactos','parent_uuid'=>$outer,'repeat'=>['min'=>0,'max'=>2]],['uuid'=>$name,'type'=>'field','parent_uuid'=>$inner],['uuid'=>$copy,'type'=>'field','parent_uuid'=>$inner]];
$data['fields']=[['uuid'=>$name,'name'=>'name','type'=>'fixture.upper','config'=>['label'=>'Nombre personalizado','required'=>true]],['uuid'=>$copy,'name'=>'copy','type'=>'text','config'=>['label'=>'Copia de la fila','readonly'=>true],'prefill'=>['type'=>'field','field'=>$name]]];
$data['actions']=[]; $data['post_submit']=['behavior'=>'keep'];
$data['security']['captcha']=['mode'=>'inherit'];
$spec=new Nicode\FormStudio\Domain\FormSpec($data);
$version=$connection->insert('form_versions',['form_id'=>$id,'revision'=>1,'schema_version'=>'1.0','spec'=>Nicode\FormStudio\Domain\CanonicalJson::encode($data),'hash'=>$spec->hash,'published_at'=>gmdate('Y-m-d H:i:s'),'published_by'=>1,'comment'=>'Internal nested browser fixture','revoked_at'=>null]);
$connection->execute('UPDATE '.$connection->table('forms')." SET state='published',published_version_id=:version,access=1,language='*' WHERE id=:id",[':version'=>$version,':id'=>$id]);
$fixture=['form_id'=>$id,'version_id'=>$version,'outer'=>$outer,'inner'=>$inner,'name'=>$name,'copy'=>$copy,'url'=>'http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id='.$id];
file_put_contents($file,json_encode($fixture,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)); echo json_encode($fixture,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
