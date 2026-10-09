<?php
declare(strict_types=1);
use Nicode\FormStudio\Domain\{Uuid, FormSpec, FieldAddress};
use Nicode\FormStudio\Infrastructure\Joomla\RequestAdapter;

test('native repeated request parsing preserves row values and native files while rejecting foreign scopes', function (): void {
    [$group,$field,$file,$one,$two] = array_map(static fn()=>Uuid::create(),range(1,5));
    $spec = new FormSpec(['schema_version'=>'1.0','elements'=>[
        ['uuid'=>$group,'type'=>'repeatable-group','repeat'=>['min'=>1,'max'=>2]],
        ['uuid'=>$field,'type'=>'field','parent_uuid'=>$group],['uuid'=>$file,'type'=>'field','parent_uuid'=>$group],
    ],'fields'=>[['uuid'=>$field,'type'=>'multiselect'],['uuid'=>$file,'type'=>'multiple-files']],'rules'=>[]]);
    $key = static fn($id,$row)=>(new FieldAddress($id,[['group'=>$group,'instance'=>$row]]))->key();
    $rows = [$group=>[$one,$two]];
    $values = [$key($field,$one)=>[' x ','😀'],$key($field,$two)=>[]];
    $post = ['form_id'=>'1','version_id'=>'2','attempt'=>'signed','nfs_instances'=>json_encode($rows),'nfs_values'=>json_encode($values)];
    $fileKey=$key($file,$two);
    $files=['nfs'=>['name'=>[$fileKey=>['row.txt']],'type'=>[$fileKey=>['forged/mime']],'tmp_name'=>[$fileKey=>['/untrusted/row']],'error'=>[$fileKey=>[UPLOAD_ERR_OK]],'size'=>[$fileKey=>[999]]]];
    $parsed=RequestAdapter::requestInstances(nativeInput($post,$files),$spec);
    same($rows,$parsed->instances->declarations()); same($values,$parsed->request->values);
    same([$fileKey=>[['name'=>'row.txt','tmp_name'=>'/untrusted/row','error'=>UPLOAD_ERR_OK]]],$parsed->request->files);
    $traditional=$post; unset($traditional['nfs_values']); $traditional['nfs']=$values;
    same($values,RequestAdapter::requestInstances(nativeInput($traditional),$spec)->request->values);
    foreach (['[]','null','{',json_encode([$group=>[$one,$one]]),json_encode([$group=>[$one,$two,Uuid::create()]]),str_repeat(' ',2097153)] as $bad) {
        raises(InvalidArgumentException::class,fn()=>RequestAdapter::requestInstances(nativeInput(array_replace($post,['nfs_instances'=>$bad])),$spec));
    }
    foreach ([$field,$key($field,Uuid::create())] as $foreign) {
        raises(InvalidArgumentException::class,fn()=>RequestAdapter::requestInstances(nativeInput(array_replace($post,['nfs_values'=>json_encode([$foreign=>'bad'])])),$spec));
    }
    raises(InvalidArgumentException::class,fn()=>RequestAdapter::requestInstances(nativeInput($post+['nfs'=>$values]),$spec));
    $foreignFiles=$files; $foreignKey=$key($file,Uuid::create());
    foreach ($foreignFiles['nfs'] as &$part) { $part=[$foreignKey=>array_values($part)[0]]; }
    unset($part);
    raises(InvalidArgumentException::class,fn()=>RequestAdapter::requestInstances(nativeInput($post,$foreignFiles),$spec));
    raises(InvalidArgumentException::class,fn()=>RequestAdapter::requestInstances(nativeInput($post,method:'GET'),$spec));
    $missing=RequestAdapter::requestInstances(nativeInput(array_replace($post,['nfs_instances'=>'{}','nfs_values'=>'{}'])),$spec);
    same([$group=>['min_instances']],$missing->instances->minimumErrors());
});
