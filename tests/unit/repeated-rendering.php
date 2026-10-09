<?php
declare(strict_types=1);
use Nicode\FormStudio\Domain\{Uuid, FormSpec, FieldAddress};
use Nicode\FormStudio\Rendering\{CoreFieldRenderer, FieldRendererRegistry, FormRenderer, PublicSpec, RenderContext};
use Nicode\FormStudio\Rules\PresentationState;

test('shared renderer gives repeated controls unique labels names errors and nested row groups', function (): void {
    [$outer,$inner,$field,$one,$two,$child] = array_map(static fn()=>Uuid::create(),range(1,6));
    $elements = [
        ['uuid'=>$outer,'type'=>'repeatable-group','title'=>'People <safe>','repeat'=>['min'=>1,'max'=>2]],
        ['uuid'=>$inner,'type'=>'repeatable-group','title'=>'Phones','parent_uuid'=>$outer,'repeat'=>['min'=>1,'max'=>2]],
        ['uuid'=>$field,'type'=>'field','parent_uuid'=>$inner],
    ];
    $spec = new FormSpec(['schema_version'=>'1.0','uuid'=>Uuid::create(),'elements'=>$elements,'fields'=>[
        ['uuid'=>$field,'name'=>'phone','type'=>'text','config'=>['label'=>'Phone <safe>','required'=>true]],
    ],'rules'=>[]]);
    $scope = static fn($row)=>[['group'=>$outer,'instance'=>$row]];
    $innerKey = static fn($row)=>(new FieldAddress($inner,$scope($row)))->key();
    $key = static fn($row)=>(new FieldAddress($field,[...$scope($row),['group'=>$inner,'instance'=>$child]]))->key();
    $rows = [$outer=>[$one,$two],$innerKey($one)=>[$child],$innerKey($two)=>[$child]];
    $state = (new PresentationState(registry(),rules()))->evaluateInstances($spec,$rows,[],[],[$key($one)=>'first',$key($two)=>'<script>second</script>']);
    $registry = new FieldRendererRegistry(); $registry->register('text',new CoreFieldRenderer());
    $renderer = new FormRenderer($registry,new PublicSpec(registry()));
    foreach (['component','module'] as $instance) {
        $html = $renderer->renderInstances($spec,$rows,new RenderContext($instance,1,2,'/index.php','csrf','attempt'),$state,[$key($two)=>['Required']]);
        $dom = new DOMDocument(); @$dom->loadHTML($html); $xpath = new DOMXPath($dom);
        same(4,$xpath->query('//*[@data-nfs-repeat-row]')->length);
        same(7,$xpath->query('//button[@name="row_change"][@formnovalidate]')->length);
        same(1,$xpath->query('//button[@data-nfs-row-change="add"][@disabled]')->length);
        same(2,$xpath->query('//button[@data-nfs-row-change="remove"][@disabled]')->length);
        foreach($xpath->query('//button[@name="row_change"]') as $button) {
            $change=json_decode($button->getAttribute('value'),true,512,JSON_THROW_ON_ERROR);
            same(true,array_key_exists($change['group'],$rows));
            same(true,$button->getAttribute('aria-label')!=='');
            if($change['operation']==='remove') { same(true,in_array($change['row'],$rows[$change['group']],true)); }
        }
        $ids = []; foreach ($xpath->query('//*[@id]') as $node) { $ids[] = $node->getAttribute('id'); } same(count($ids),count(array_unique($ids)));
        foreach ([$one,$two] as $row) {
            $input = $xpath->query('//input[@data-nfs-input="'.$key($row).'"]')->item(0);
            same('nfs['.$key($row).']',$input->getAttribute('name'));
            same($instance.'-'.$key($row),$input->getAttribute('id'));
            same(1,$xpath->query('//label[@for="'.$input->getAttribute('id').'"]')->length);
        }
        $link = $xpath->query('//div[contains(@class,"nfs-validation-summary")]//a')->item(0);
        same($instance.'-'.$key($two).'-field',substr($link->getAttribute('href'),1));
        same(1,$xpath->query('//*[@id="'.substr($link->getAttribute('href'),1).'"]')->length);
        same(false,str_contains($html,'<script>second'));
        same($rows,json_decode($xpath->query('//input[@name="nfs_instances"]')->item(0)->getAttribute('value'),true));
        $public = json_decode($xpath->query('//script[@data-nfs-definition]')->item(0)->textContent,true);
        same([$key($one),$key($two)],array_column($public['fields'],'uuid'));
    }
    $rows[$innerKey($two)] = [];
    $checked = validation()->validateInstances($spec,$rows,[$key($one)=>'ok']);
    $html = $renderer->renderInstances($spec,$rows,new RenderContext('empty',1,2,'/index.php','csrf','attempt'),$checked->rules,$checked->errors);
    $dom = new DOMDocument(); @$dom->loadHTML($html); $xpath = new DOMXPath($dom);
    same(1,$xpath->query('//*[@id="empty-'.$innerKey($two).'-field"]')->length);
    same(1,$xpath->query('//*[@data-nfs-error="'.$innerKey($two).'"][not(@hidden)]')->length);
    same(true,str_contains($html,'Phones: min_instances'));
});
