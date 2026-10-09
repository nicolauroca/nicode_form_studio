<?php
declare(strict_types=1);

// Isolated parent harness authenticates administration and guards the database/site.
require_once $root.'/src/lib_nicode_form_studio/autoload.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=13367;dbname=formstudio_joomla;charset=utf8mb4',$configuration->user,$configuration->password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$original=$pdo->query("SELECT params FROM j6_extensions WHERE element='com_nicode_form_studio' AND type='component'")->fetchColumn();
$params=json_decode($original,true,flags:JSON_THROW_ON_ERROR); $params['redirect_hosts']='thanks.example.test';
$setParams=$pdo->prepare("UPDATE j6_extensions SET params=? WHERE element='com_nicode_form_studio' AND type='component'");
$cookie=$root.'/build/navigation-cookie-'.bin2hex(random_bytes(6)).'.txt'; $id=null;
$http=static function(string $url,?array $post=null)use($cookie):array {
    if(!str_starts_with($url,'http://127.0.0.1:13371/'))throw new RuntimeException('Unexpected navigation fixture destination.');
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_PROXY=>'',CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_HEADER=>true]);
    if($post!==null){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post));}
    $raw=curl_exec($ch);if(!is_string($raw))throw new RuntimeException('Navigation fixture connection failed.');
    $offset=curl_getinfo($ch,CURLINFO_HEADER_SIZE);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_setopt($ch,CURLOPT_COOKIELIST,'FLUSH');
    return ['status'=>$status,'headers'=>substr($raw,0,$offset),'body'=>substr($raw,$offset)];
};
try {
    $setParams->execute([json_encode($params,JSON_THROW_ON_ERROR)]);
    $id=$api('create',['name'=>'Native navigation acceptance','alias'=>'native-navigation-'.bin2hex(random_bytes(6))])['id'];
    $draft=$api('record',query:['id'=>$id])['draft'];$field=Nicode\FormStudio\Domain\Uuid::create();
    $draft['elements']=[['uuid'=>$field,'type'=>'field']];$draft['fields']=[['uuid'=>$field,'name'=>'answer','type'=>'text','config'=>['required'=>true]]];
    $draft['security']['captcha']=['mode'=>'none'];$menu=json_decode(file_get_contents($root.'/build/native-menu-fixture.json'),true,flags:JSON_THROW_ON_ERROR);
    $checks=[];
    foreach(['internal'=>['url'=>'/thanks-native-test'],'menu'=>['menu_id'=>$menu['menu_id']],'approved'=>['url'=>'https://thanks.example.test/received']] as $kind=>$config){
        $draft['actions']=[['uuid'=>Nicode\FormStudio\Domain\Uuid::create(),'type'=>'redirect','enabled'=>true,'order'=>0,'failure_policy'=>'blocking','config'=>$config]];
        $record=$api('record',query:['id'=>$id]);$saved=$api('save',['id'=>$id,'revision'=>(int)$record['form']['draft_revision'],'draft'=>$draft]);
        $published=$api('publish',['id'=>$id,'revision'=>$saved['revision']]);
        foreach(['json','html'] as $format){
            $page=$http('http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id='.$id);$xp=$dom($page['body']);$form=$xp->query('//form[@data-nfs-form]')->item(0);
            $assert($page['status']===200 && $form instanceof DOMElement,'Navigation form did not render.');$post=[];
            foreach($xp->query('.//input[@type="hidden"]',$form)as$input){$post[$input->getAttribute('name')]=$input->getAttribute('value');}
            $post['nfs']=[$field=>'Native '.$kind.' '.$format];$post['redirect']='https://unapproved.example.test/forged';
            if($format==='json')$post['format']='json';
            $response=$http('http://127.0.0.1:13371'.$form->getAttribute('action'),$post);
            if($format==='json'){$result=json_decode($response['body'],true,flags:JSON_THROW_ON_ERROR);$assert($response['status']===200 && ($result['accepted']??false) && ($result['processed']??false),'Navigation JSON failed.');$destination=$result['redirect']??'';}
            else{$assert($response['status']===303,'Traditional navigation did not return 303.');preg_match('/^Location:\s*(.+)$/mi',$response['headers'],$match);$destination=trim($match[1]??'');}
            $expected=$kind==='menu'?'formstudio-native-menu-acceptance':$config['url'];
            $assert($kind==='menu'?str_contains($destination,$expected):($destination===$expected || $destination==='http://127.0.0.1:13371'.$expected),'Wrong navigation destination: '.$kind.'/'.$format);
            $assert(!str_contains($destination,'unapproved.example.test')&&!str_contains($destination,'/administrator/'),'Visitor or admin context controlled destination.');
            $checks[]=$kind.'/'.$format;
        }
    }
    $record=$api('record',query:['id'=>$id]);$draft['actions'][0]['config']=['url'=>'https://unapproved.example.test/'];
    $saved=$api('save',['id'=>$id,'revision'=>(int)$record['form']['draft_revision'],'draft'=>$draft]);$rejected=$api('publish',['id'=>$id,'revision'=>$saved['revision']],expected:422);
    $after=$api('record',query:['id'=>$id]);$assert(in_array('action.navigation',array_column($rejected['diagnostics'],'code'),true)&&(int)$after['form']['published_version_id']===$published['version_id'],'Unapproved navigation did not preserve the published version.');
    $count=$pdo->prepare('SELECT COUNT(*) FROM j6_nicode_form_studio_submissions WHERE form_id=?');$count->execute([$id]);$assert((int)$count->fetchColumn()===6,'Navigation lost or duplicated accepted responses.');
    file_put_contents($root.'/build/native-navigation-results.json',json_encode(['passed'=>true,'timestamp'=>gmdate(DATE_ATOM),'form_id'=>$id,'checks'=>$checks,'external_requests_followed'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo "Native navigation: internal/menu/approved HTTPS in JSON and HTML 303, forged input ignored, unapproved publication rejected and six canonical responses passed.\n";
} finally {
    $setParams->execute([$original]);
    if(is_file($cookie))unlink($cookie);
    if($id!==null){$current=$api('record',query:['id'=>$id]);if($current['form']['state']==='published')$api('deactivate',['id'=>$id,'revision'=>(int)$current['form']['draft_revision'],'state'=>'unpublished']);}
}
