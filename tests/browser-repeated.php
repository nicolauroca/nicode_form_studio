<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/lib_nicode_form_studio/autoload.php';
use Nicode\FormStudio\Domain\{Uuid, FormSpec, RepeatedInstances};
use Nicode\FormStudio\Registry\{FieldTypeRegistry, RuleOperatorRegistry, RuleEffectRegistry};
use Nicode\FormStudio\Rules\{RuleEngine, ConditionEvaluator, PresentationState};
use Nicode\FormStudio\Rendering\{FormRenderer, FieldRendererRegistry, CoreFieldRenderer, PublicSpec, RenderContext};

$types = new FieldTypeRegistry(); Nicode\FormStudio\Field\CoreFieldTypes::register($types);
$rules = new RuleEngine(new ConditionEvaluator(RuleOperatorRegistry::core()),RuleEffectRegistry::core(),$types);
[$outer,$inner,$name,$copy,$toggle,$panel,$email] = array_map(static fn()=>Uuid::create(),range(1,7));
$elements = [
    ['uuid'=>$outer,'type'=>'repeatable-group','title'=>'Familia','repeat'=>['min'=>2,'max'=>3]],
    ['uuid'=>$inner,'type'=>'repeatable-group','title'=>'Contacto','parent_uuid'=>$outer,'repeat'=>['min'=>1,'max'=>2]],
    ['uuid'=>$name,'type'=>'field','parent_uuid'=>$inner],
    ['uuid'=>$copy,'type'=>'field','parent_uuid'=>$inner],
    ['uuid'=>$toggle,'type'=>'field','parent_uuid'=>$inner],
    ['uuid'=>$panel,'type'=>'fieldset','title'=>'Detalle','parent_uuid'=>$inner],
    ['uuid'=>$email,'type'=>'field','parent_uuid'=>$panel],
];
$fields = [
    ['uuid'=>$name,'name'=>'name','type'=>'text','config'=>['label'=>'Nombre','required'=>true,'default'=>'Ana']],
    ['uuid'=>$copy,'name'=>'copy','type'=>'text','config'=>['label'=>'Copia por fila','readonly'=>true],'prefill'=>['type'=>'field','field'=>$name]],
    ['uuid'=>$toggle,'name'=>'detail','type'=>'checkbox','config'=>['label'=>'Mostrar detalle','default'=>true]],
    ['uuid'=>$email,'name'=>'email','type'=>'email','config'=>['label'=>'Correo','required'=>true]],
];
$spec = new FormSpec(['schema_version'=>'1.0','uuid'=>Uuid::create(),'name'=>'Repeated fixture','elements'=>$elements,'fields'=>$fields,'rules'=>[
    ['uuid'=>Uuid::create(),'when'=>['field'=>$toggle,'operator'=>'equals','value'=>false],'effects'=>[['target'=>$panel,'type'=>'hide']]],
]]);
$instances = RepeatedInstances::initial($elements); $rows = $instances->declarations();
$state = (new PresentationState($types,$rules))->evaluateInstances($spec,$rows);
$renderers = new FieldRendererRegistry(); foreach (['text','checkbox','email'] as $type) { $renderers->register($type,new CoreFieldRenderer()); }
$renderer = new FormRenderer($renderers,new PublicSpec($types)); $html = '';
foreach (['principal','modulo'] as $id) {
    $html .= '<section><h2>'.($id==='principal'?'Formulario principal':'Segundo formulario').'</h2>';
    $html .= $renderer->renderInstances($spec,$rows,new RenderContext($id,1,2,'/index.php','csrf','',preview:true),$state).'</section>';
}
$minimumSpec = new FormSpec(['schema_version'=>'1.0','uuid'=>Uuid::create(),'elements'=>[
    ['uuid'=>$toggle,'type'=>'field'],
    ['uuid'=>$inner,'type'=>'repeatable-group','title'=>'Contactos obligatorios','repeat'=>['min'=>1,'max'=>2]],
    ['uuid'=>$email,'type'=>'field','parent_uuid'=>$inner],
],'fields'=>[
    ['uuid'=>$toggle,'name'=>'active','type'=>'checkbox','config'=>['label'=>'Activar contactos','default'=>true]],
    $fields[3],
],'rules'=>[['uuid'=>Uuid::create(),'when'=>['field'=>$toggle,'operator'=>'equals','value'=>false],'effects'=>[['target'=>$inner,'type'=>'hide']]]]]);
$minimumRows = [$inner=>[]];
$minimumState = (new PresentationState($types,$rules))->evaluateInstances($minimumSpec,$minimumRows);
$html .= '<section><h2>Mínimos activos</h2>'.$renderer->renderInstances($minimumSpec,$minimumRows,new RenderContext('minimos',1,2,'/index.php','csrf','',preview:true),$minimumState).'</section>';
$html = str_replace('</form>','<button type="button" data-test-validate>Comprobar campos</button></form>',$html);
$page = '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Filas repetidas — prueba aislada</title><link rel="stylesheet" href="assets/css/formstudio.css"><style>body{font-family:system-ui;margin:24px auto;padding:0 16px;max-width:1000px}section>h2{margin-top:2rem}.nfs-repeat-row{margin:1rem 0;padding:1rem}fieldset{min-width:0}input{max-width:100%;box-sizing:border-box}</style><h1>Filas repetidas</h1><p>Prueba interna de representación y reglas. Envío deshabilitado.</p>'.$html.'<script type="module">import {createInitializer} from "./assets/js/initialize.js"; import {createFormInstance} from "./assets/js/form-instance.js"; createInitializer(form => Promise.resolve(createFormInstance(form)).then(runtime => { form.querySelector("[data-test-validate]").addEventListener("click", () => runtime.validate()); return runtime; }))(document);</script></html>';
$directory = dirname(__DIR__).'/build/repeated-browser';
if (!is_dir($directory)) { mkdir($directory,0770,true); }
file_put_contents($directory.'/index.html',$page);
echo "Repeated browser fixture generated. Copy media JS/CSS to its assets directory before serving.\n";
