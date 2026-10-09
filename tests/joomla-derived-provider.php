<?php
 declare(strict_types=1);
 $_SERVER['HTTP_HOST']='127.0.0.1:13371'; $_SERVER['REQUEST_URI']='/'; $_SERVER['SCRIPT_NAME']=$_SERVER['PHP_SELF']='/index.php';
 require __DIR__ . '/joomla-config.php';
 $runtime=new Joomla\DI\Container($container);
 $runtime->registerServiceProvider(new Nicode\FormStudio\Infrastructure\Joomla\RuntimeProvider($app,new Joomla\Registry\Registry(['captcha_mode'=>'none']),$site));
 $administration=$runtime->get(Nicode\FormStudio\Application\FormAdministration::class);
 $form=$administration->create('Derived provider normalization','derived-provider-'.bin2hex(random_bytes(6)),(int)$admin->id);
 $draft=$administration->edit($form,(int)$admin->id)['draft'];
 $source=Nicode\FormStudio\Domain\Uuid::create(); $target=Nicode\FormStudio\Domain\Uuid::create();
 $draft['elements']=[['uuid'=>$source,'type'=>'field','parent_uuid'=>null],['uuid'=>$target,'type'=>'field','parent_uuid'=>null]];
 $draft['fields']=[['uuid'=>$source,'type'=>'text','name'=>'source','config'=>['label'=>'Source text','trim'=>false,'default'=>'lower']],['uuid'=>$target,'type'=>'fixture.upper','name'=>'copy','config'=>['label'=>'Derived uppercase','readonly'=>true],'prefill'=>['type'=>'field','field'=>$source]]];
 $revision=$administration->save($form,0,$draft,(int)$admin->id); $administration->publish($form,$revision,(int)$admin->id);
 echo 'Published derived fixture: http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id='.$form.PHP_EOL;
