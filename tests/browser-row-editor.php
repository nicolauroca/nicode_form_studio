<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/lib_nicode_form_studio/autoload.php';
use Nicode\FormStudio\Domain\{Uuid,FormSpec,RepeatedInstances,FieldAddress};
use Nicode\FormStudio\Rendering\{FormRenderer,FieldRendererRegistry,CoreFieldRenderer,PublicSpec,RenderContext};
use Nicode\FormStudio\Registry\{FieldTypeRegistry,RuleOperatorRegistry,RuleEffectRegistry};
use Nicode\FormStudio\Rules\{RuleEngine,ConditionEvaluator,PresentationState};
$types=new FieldTypeRegistry(); Nicode\FormStudio\Field\CoreFieldTypes::register($types);
$rules=new RuleEngine(new ConditionEvaluator(RuleOperatorRegistry::core()),RuleEffectRegistry::core(),$types);
[$group,$name,$file]=array_map(static fn()=>Uuid::create(),[1,2,3]);
$data=['schema_version'=>'1.0','uuid'=>Uuid::create(),'name'=>'Browser row test','elements'=>[
 ['uuid'=>$group,'type'=>'repeatable-group','title'=>'Personas','repeat'=>['min'=>1,'max'=>2]],
 ['uuid'=>$name,'type'=>'field','parent_uuid'=>$group],['uuid'=>$file,'type'=>'field','parent_uuid'=>$group],
],'fields'=>[['uuid'=>$name,'name'=>'name','type'=>'text','config'=>['label'=>'Nombre','default'=>'Initial']],['uuid'=>$file,'name'=>'file','type'=>'file','config'=>['label'=>'Archivo','extensions'=>['txt'],'mime_types'=>['text/plain'],'max_bytes'=>1024]]],'rules'=>[]];
$spec=new FormSpec($data); $instances=RepeatedInstances::initial($data['elements']); $added=$instances->withAddedRow(new FieldAddress($group));
$registry=new FieldRendererRegistry(); foreach(['text','file'] as $type) { $registry->register($type,new CoreFieldRenderer()); }
$renderer=new FormRenderer($registry,new PublicSpec($types)); $presentation=new PresentationState($types,$rules);
$render=static fn(string $id,array $rows): string=>$renderer->renderInstances($spec,$rows,new RenderContext($id,1,2,'/index.php','csrf','unchanged-attempt'),$presentation->evaluateInstances($spec,$rows));
$fixture=['group'=>$group,'name'=>$name,'file'=>$file,'rows'=>$instances->declarations(),'added'=>$added->declarations(),'addHtml'=>$render('main',$added->declarations()),'removeHtml'=>$render('main',$instances->declarations())];
$directory=dirname(__DIR__).'/build/row-editor-browser'; if(!is_dir($directory)) { mkdir($directory,0770,true); }
$html='<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Prueba de edición dinámica</title><link rel="stylesheet" href="assets/css/formstudio.css"><style>body{font-family:system-ui;max-width:900px;margin:24px auto;padding:16px}fieldset{margin:12px 0;padding:12px;min-width:0}#report{white-space:pre-wrap;padding:16px;background:#eef5ed}</style><h1>Edición dinámica de filas</h1><pre id="report">Ejecutando pruebas…</pre>'.$render('main',$instances->declarations()).'<h2>Formulario independiente</h2>'.$render('other',$instances->declarations()).'<script type="application/json" id="fixture">'.json_encode($fixture,JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP).'</script><script type="module" src="test.js"></script></html>';
file_put_contents($directory.'/index.html',$html); copy(__DIR__.'/fixtures/row-editor-browser.js',$directory.'/test.js');
echo "Browser row editor fixture generated.\n";
