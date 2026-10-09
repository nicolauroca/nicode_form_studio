<?php
declare(strict_types=1);
require __DIR__.'/joomla-config.php';
$runtime=$app->bootComponent('com_nicode_form_studio')->runtime($app);
$service=$runtime->get(Nicode\FormStudio\Application\FormAdministration::class);
$forms=$runtime->get(Nicode\FormStudio\Infrastructure\Database\FormRepository::class);
$db=$container->get(Joomla\Database\DatabaseInterface::class);
$module=new Joomla\CMS\Table\Module($db,$container->get(Joomla\Event\DispatcherInterface::class));
$id=$service->create('Module <title> acceptance','module-presentation-'.bin2hex(random_bytes(5)),(int)$admin->id);
$override=$site.'/templates/cassiopeia/html/mod_nicode_form_studio/acceptance-'.bin2hex(random_bytes(5)).'.php';
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
try{
    $draft=$forms->draft($id);$field=Nicode\FormStudio\Domain\Uuid::create();
    $draft['metadata']['description']='Published description <script>not executable</script>';
    $draft['elements']=[['uuid'=>$field,'type'=>'field']];$draft['fields']=[['uuid'=>$field,'name'=>'answer','type'=>'text','config'=>['label'=>'Module answer']]];
    $draft['security']['captcha']=['mode'=>'none'];$revision=$service->save($id,0,$draft,(int)$admin->id);$service->publish($id,$revision,(int)$admin->id);
    $module->note='';$module->content='';$module->title='Module presentation fixture';$module->module='mod_nicode_form_studio';$module->position='bottom-a';$module->published=1;$module->access=1;$module->showtitle=1;$module->client_id=0;$module->language='*';$module->ordering=99;
    $params=['form_id'=>$id,'cache'=>0,'show_form_title'=>1,'show_description'=>1,'form_class'=>'custom-one second_class bad"onclick=alert(1) 1invalid','layout'=>'default','unavailable_mode'=>'hide'];
    $save=static function()use($module,&$params):void{$module->params=json_encode($params,JSON_THROW_ON_ERROR);if(!$module->check()||!$module->store())throw new RuntimeException('Module fixture save failed: '.$module->getError());};$save();
    $db->setQuery('INSERT INTO #__modules_menu (moduleid,menuid) VALUES ('.(int)$module->id.',0)')->execute();
    $fetch=static function()use($module):array{
        $ch=curl_init('http://127.0.0.1:13371/index.php?module_presentation='.bin2hex(random_bytes(4)));curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_PROXY=>'']);$html=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        if($status!==200||!is_string($html))throw new RuntimeException('Native module page unavailable.');
        $doc=new DOMDocument();$prior=libxml_use_internal_errors(true);try{$doc->loadHTML($html);}finally{libxml_clear_errors();libxml_use_internal_errors($prior);}
        $xp=new DOMXPath($doc);return [$xp,$xp->query('//*[@data-nfs-module="'.(int)$module->id.'"]')->item(0),$html];
    };
    [$xp,$node,$html]=$fetch();$assert($node instanceof DOMElement,'Module wrapper missing.');
    $assert($node->getAttribute('class')==='nfs-module custom-one second_class','Module CSS token policy failed.');
    $assert($xp->query('./h2',$node)->item(0)?->textContent===$draft['name']&&$xp->query('./p[@class="nfs-module-description"]',$node)->item(0)?->textContent===$draft['metadata']['description'],'Published title/description missing.');
    $assert($xp->query('.//script[not(@type="application/json")]|.//*[@onclick]',$node)->length===0,'Module presentation injected markup.');
    $assert(str_contains($html,'Module presentation fixture'),'Native Joomla module title missing.');
    $params['show_form_title']=$params['show_description']=0;$save();[$xp,$node]=$fetch();
    $assert($xp->query('./h2|./p[@class="nfs-module-description"]',$node)->length===0&&$xp->query('.//form[@data-nfs-form]',$node)->length===1,'Presentation switches changed runtime or retained headings.');
    if(!is_dir(dirname($override)))mkdir(dirname($override),0770,true);
    file_put_contents($override,"<?php defined('_JEXEC') or die; echo '<p data-module-override>Native override</p>'; require JPATH_SITE.'/modules/mod_nicode_form_studio/tmpl/default.php';");
    $params['layout']='cassiopeia:'.pathinfo($override,PATHINFO_FILENAME);$save();[$xp,$node]=$fetch();
    $assert($xp->query('//*[@data-module-override]')->length===1&&$node instanceof DOMElement,'Native module layout override not used.');
    $params['layout']='default';$save();$record=$forms->get($id);$service->deactivate($id,(int)$record['draft_revision'],(int)$admin->id);
    [$xp,$node]=$fetch();$assert($node===null,'Unavailable hidden module exposed content.');
    $params['unavailable_mode']='message';$params['show_form_title']=$params['show_description']=1;$save();[$xp,$node]=$fetch();
    $assert($node instanceof DOMElement&&$xp->query('.//p[@role="status"]',$node)->length===1&&$xp->query('.//form|.//h2',$node)->length===0&&!str_contains($node->textContent,'Published description'),'Unavailable message disclosed form data or controls.');
    $native=Joomla\CMS\Form\Form::getInstance('formstudio.module.presentation.test',$site.'/modules/mod_nicode_form_studio/mod_nicode_form_studio.xml',[],false,'/extension/config');
    foreach(['show_form_title','show_description','form_class','unavailable_mode','layout']as$name){$assert($native->getField($name,'params')!==false,'Native module editor missing '.$name);}
    file_put_contents($root.'/build/native-module-presentation-results.json',json_encode(['passed'=>true,'timestamp'=>gmdate(DATE_ATOM),'form_id'=>$id,'module_id'=>(int)$module->id,'checks'=>['published escaped title/description','native module title','CSS token filtering','presentation switches','native layout override','unavailable hide/message','native configuration fields']],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo "Native module presentation: escaped title/description, CSS tokens, switches, native title/layout, private unavailable states and editor fields passed.\n";
}finally{
    if(is_file($override))unlink($override);
    if($module->id){$db->setQuery('DELETE FROM #__modules_menu WHERE moduleid='.(int)$module->id)->execute();$module->delete();}
    $record=$forms->get($id);if($record['state']==='published')$service->deactivate($id,(int)$record['draft_revision'],(int)$admin->id);
}
