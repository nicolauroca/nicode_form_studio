<?php
declare(strict_types=1);

test('attachment publication validates local file references and historical email policy', function (): void {
    $compiler=publicationActionCompiler(); $draft=withSecond(definition(),'email');
    [$file,$email]=array_column($draft['fields'],'uuid');
    $draft['fields'][0]['type']='file'; $draft['fields'][0]['config']=['extensions'=>['txt'],'mime_types'=>['text/plain'],'max_bytes'=>1024];
    foreach(['email_notification','email_autoresponse'] as $type) {
        $draft['actions']=[['uuid'=>Nicode\FormStudio\Domain\Uuid::create(),'type'=>$type,'config'=>['to'=>['staff@example.test'],'email_field'=>$email,'subject'=>'Received','body_text'=>'Evidence','attachment_fields'=>[$file]]]];
        foreach(['file','multiple-files'] as $fileType) {
            $draft['fields'][0]['type']=$fileType; same(true,$compiler->compile($draft)->successful());
        }
        foreach([$email,Nicode\FormStudio\Domain\Uuid::create(),[]] as $target) {
            $bad=$draft; $bad['actions'][0]['config']['attachment_fields']=[$target];
            $result=$compiler->compile($bad); same(false,$result->successful());
            $diagnostics=array_values(array_filter($result->diagnostics,static fn($d)=>$d->code==='action.attachment_reference'));
            same('/actions/0/config/attachment_fields/0',$diagnostics[0]->path);
        }
        foreach([['sensitive'=>true],['include_email'=>false],['sensitive'=>true,'include_email'=>false]] as $policy) {
            $bad=$draft; $bad['fields'][0]=array_replace($bad['fields'][0],$policy);
            $result=$compiler->compile($bad); same(false,$result->successful()); same(true,in_array('action.attachment_policy',array_column($result->diagnostics,'code'),true));
        }
        $allowed=$draft; $allowed['fields'][0]['sensitive']=true; $allowed['fields'][0]['include_email']=true;
        foreach(['full','metadata','none'] as $mode) { $allowed['persistence']['mode']=$mode; $allowed['fields'][0]['persist']=false; same(true,$compiler->compile($allowed)->successful()); }
        $copy=(new Nicode\FormStudio\Domain\DefinitionRemapper())->duplicate($draft,Nicode\FormStudio\Domain\Uuid::create());
        same([$copy['identities'][$file]],$copy['definition']['actions'][0]['config']['attachment_fields']);
        same([$file],$draft['actions'][0]['config']['attachment_fields']);
        same(true,$compiler->compile($copy['definition'])->successful());
        $actions=new Nicode\FormStudio\Registry\ActionRegistry();
        $mail=new class implements Nicode\FormStudio\Contract\MailTransportInterface { public function send(Nicode\FormStudio\Actions\MailMessage $message):void { throw new RuntimeException('Import must not send mail.'); } };
        $actions->register(new Nicode\FormStudio\Actions\EmailAction($mail,new Nicode\FormStudio\Actions\TokenTemplate(),$type==='email_autoresponse'));
        $packages=new Nicode\FormStudio\Transfer\DefinitionPackage(['fields'=>registry(),'actions'=>$actions]);
        $preview=new Nicode\FormStudio\Transfer\ImportPreview($packages,$compiler);
        $json=Nicode\FormStudio\Domain\CanonicalJson::encode($packages->export($draft));
        $result=$preview->analyze($json,'duplicate'); same(true,$result['definition_valid']);
        same([$file],$result['package']['definition']['actions'][0]['config']['attachment_fields']);
        $bad=$draft; $bad['fields'][0]['include_email']=false;
        $result=$preview->analyze(Nicode\FormStudio\Domain\CanonicalJson::encode($packages->export($bad)),'duplicate');
        same(false,$result['definition_valid']); same(true,$result['can_import_draft']);
        same(true,in_array('action.attachment_policy',array_column($result['diagnostics'],'code'),true));
    }
});
