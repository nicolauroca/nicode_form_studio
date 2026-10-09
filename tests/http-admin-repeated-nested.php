<?php
declare(strict_types=1);

// Uses the real administrator and guest sessions of the isolated repeated runner.
(function () use ($api, $visitorRequest, $dom, $assert, $prefillDb, $root): void {
    $created = $api('create', ['name'=>'Nested publication acceptance', 'alias'=>'nested-publish-'.bin2hex(random_bytes(6))]);
    $id = $created['id']; $record = $api('record', query:['id'=>$id]); $draft = $record['draft'];
    [$outer, $inner, $field] = array_map(static fn()=>Nicode\FormStudio\Domain\Uuid::create(), [1,2,3]);
    $draft['elements'] = [
        ['uuid'=>$outer,'type'=>'repeatable-group','repeat'=>['min'=>1,'max'=>2]],
        ['uuid'=>$inner,'type'=>'repeatable-group','parent_uuid'=>$outer,'repeat'=>['min'=>1,'max'=>2]],
        ['uuid'=>$field,'type'=>'field','parent_uuid'=>$inner],
    ];
    $draft['fields'] = [['uuid'=>$field,'name'=>'nested_answer','type'=>'text','config'=>['required'=>true,'label'=>'Nested answer']]];
    $draft['actions'] = []; $draft['security']['captcha'] = ['mode'=>'none'];
    $draft['post_submit'] = ['behavior'=>'keep'];
    try {
        $saved = $api('save', ['id'=>$id,'revision'=>0,'draft'=>$draft]);
        $published = $api('publish', ['id'=>$id,'revision'=>$saved['revision']]);
        foreach (['json','html'] as $format) {
            $page = $visitorRequest('http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id='.$id);
            $xp = $dom($page['body']); $form = $xp->query('//form[@data-nfs-form]')->item(0);
            $assert($page['status']===200 && $form instanceof DOMElement, 'Published nested form did not render.');
            $post = [];
            foreach ($xp->query('.//input[@type="hidden"]', $form) as $input) { $post[$input->getAttribute('name')]=$input->getAttribute('value'); }
            $rows = json_decode($post['nfs_instances'], true, flags:JSON_THROW_ON_ERROR);
            $parent = $rows[$outer][0]; $scope = $outer.'/'.$parent.'/'.$inner; $child = $rows[$scope][0];
            $key = $scope.'/'.$child.'/'.$field;
            $assert(count($rows)===2 && count($rows[$outer])===1 && count($rows[$scope])===1, 'Nested minimum rows or ancestry changed.');
            $post['nfs'] = [$key=>'Nested '.$format];
            if ($format==='json') { $post['format']='json'; }
            $response = $visitorRequest('http://127.0.0.1:13371'.$form->getAttribute('action'), $post);
            $assert($response['status']===200, 'Published nested submit failed.');
            if ($format==='json') { $result=json_decode($response['body'],true,flags:JSON_THROW_ON_ERROR); $assert($result['accepted']??false, 'Nested JSON rejected.'); }
            $query=$prefillDb->prepare('SELECT form_version_id,canonical_payload FROM j6_nicode_form_studio_submissions WHERE form_id=? ORDER BY id DESC'); $query->execute([$id]);
            $stored=$query->fetch(PDO::FETCH_ASSOC); $payload=json_decode($stored['canonical_payload'],true,flags:JSON_THROW_ON_ERROR);
            $assert((int)$stored['form_version_id']===(int)$published['version_id'] && $payload['instances']===$rows && $payload['values']===[$key=>'Nested '.$format], 'Nested canonical response lost its published version, ancestry or value.');
        }
        $query=$prefillDb->prepare('SELECT COUNT(*) FROM j6_nicode_form_studio_submissions WHERE form_id=?'); $query->execute([$id]);
        $assert((int)$query->fetchColumn()===2,'Nested transports did not create exactly one response each.');
        file_put_contents($root.'/build/native-nested-publication-results.json',json_encode(['passed'=>true,'timestamp'=>gmdate(DATE_ATOM),'form_id'=>$id,'version_id'=>$published['version_id'],'transports'=>['json','html']],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        echo "Native nested publication: normal save/publish, JSON/HTML submit, exact row ancestry, version and values passed.\n";
    } finally {
        $current=$api('record',query:['id'=>$id]);
        if ($current['form']['state']==='published') { $api('deactivate',['id'=>$id,'revision'=>(int)$current['form']['draft_revision'],'state'=>'unpublished']); }
    }
})();
