<?php
declare(strict_types=1);

/** Isolated fixture services; no production Joomla bootstrap or external actions. */
function repeatedUploadServices(bool $rejectCaptcha = false, ?Nicode\FormStudio\Contract\MailTransportInterface $mail = null): array
{
    $root=dirname(__DIR__);
    if(!defined('_JEXEC')) { define('_JEXEC',1); }
    require_once $root.'/build/joomla-6.0.0/libraries/vendor/autoload.php';
    require_once $root.'/src/lib_nicode_form_studio/autoload.php';
    $config=json_decode(ltrim(file_get_contents($root.'/build/database-test.json'),"\xEF\xBB\xBF"),true,flags:JSON_THROW_ON_ERROR);
    if($config['host']!=='127.0.0.1' || $config['port']!==13367 || $config['database']!=='formstudio_test') { throw new RuntimeException('Non-isolated upload database.'); }
    $driver=(new Joomla\Database\DatabaseFactory())->getDriver('mysql',['host'=>$config['host'],'port'=>$config['port'],'user'=>$config['user'],'password'=>$config['password'],'database'=>$config['database'],'prefix'=>'nfs_','charset'=>'utf8mb4']);
    $db=new Nicode\FormStudio\Infrastructure\Database\Connection($driver);
    $auth=json_decode(ltrim(file_get_contents($root.'/build/upload-test.json'),"\xEF\xBB\xBF"),true,flags:JSON_THROW_ON_ERROR); $key=hash('sha256',$auth['nonce']);
    $types=new Nicode\FormStudio\Registry\FieldTypeRegistry(); Nicode\FormStudio\Field\CoreFieldTypes::register($types);
    $actions=new Nicode\FormStudio\Registry\ActionRegistry();
    if ($mail!==null) { $actions->register(new Nicode\FormStudio\Actions\EmailAction($mail,new Nicode\FormStudio\Actions\TokenTemplate())); }
    $compiler=new Nicode\FormStudio\Compiler\FormCompiler($types,$actions,new Nicode\FormStudio\Registry\ProviderRegistry(),new Nicode\FormStudio\Registry\ProviderRegistry());
    $forms=new Nicode\FormStudio\Infrastructure\Database\FormRepository($db,$compiler);
    $jobs=new Nicode\FormStudio\Infrastructure\Database\JobRepository($db); $journal=new Nicode\FormStudio\Infrastructure\Database\UploadJournal($db,$jobs);
    $directory=$root.'/build/repeated-upload-private'; if(!is_dir($directory)) { mkdir($directory,0700,true); }
    $storage=new Nicode\FormStudio\Storage\LocalStorage($directory,$root.'/tests/http');
    $providers=new Nicode\FormStudio\Registry\StorageProviderRegistry(); $providers->register($storage);
    $submissions=new Nicode\FormStudio\Infrastructure\Database\SubmissionRepository($db,new Nicode\FormStudio\Search\IndexProjector($types),$key,$journal);
    $conditions=new Nicode\FormStudio\Rules\ConditionEvaluator(Nicode\FormStudio\Registry\RuleOperatorRegistry::core());
    $validation=new Nicode\FormStudio\Validation\ValidationEngine($types,new Nicode\FormStudio\Rules\RuleEngine($conditions,Nicode\FormStudio\Registry\RuleEffectRegistry::core(),$types));
    $captcha=new class($rejectCaptcha) implements Nicode\FormStudio\Contract\CaptchaAdapterInterface {
        public function __construct(private bool $reject) {}
        public function available():array { return []; }
        public function assertAvailable(Nicode\FormStudio\Security\CaptchaPolicy $policy):void {}
        public function render(Nicode\FormStudio\Security\CaptchaPolicy $policy,string $instance):string { return ''; }
        public function validate(Nicode\FormStudio\Security\CaptchaPolicy $policy,?string $answer):void { if($this->reject) { throw new Nicode\FormStudio\Security\CaptchaException('captcha_error'); } }
    };
    $attempts=new Nicode\FormStudio\Security\AttemptTokens($key);
    $pipeline=new Nicode\FormStudio\Application\SubmissionPipeline($forms,$submissions,new Nicode\FormStudio\Security\PublicAccess(),$attempts,$captcha,new Nicode\FormStudio\Infrastructure\Database\RateLimiter($db),$validation,new Nicode\FormStudio\Actions\ActionEngine($actions,new Nicode\FormStudio\Infrastructure\Database\ActionRunRepository($db),$conditions,$types),new Nicode\FormStudio\Submission\PostSubmit($conditions,$types,new Nicode\FormStudio\Actions\TokenTemplate(),new Nicode\FormStudio\Security\RedirectPolicy()),$providers,new Nicode\FormStudio\Storage\HttpUploadGateway(new Nicode\FormStudio\Storage\UploadInspector(),$storage,journal:$journal),uploadJournal:$journal);
    return compact('db','forms','submissions','storage','attempts','pipeline');
}
