<?php
declare(strict_types=1);
// Prepares only the named isolated Joomla fixture, with storage outside its web root.
$root = dirname(__DIR__); $site = $root . '/build/joomla-6.0.0';
$_SERVER['HTTP_HOST'] = '127.0.0.1:13371'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['PHP_SELF'] = '/index.php';
define('_JEXEC', 1); define('JPATH_BASE', $site);
require $site . '/includes/defines.php'; require $site . '/includes/framework.php'; require $root . '/src/lib_nicode_form_studio/autoload.php';
$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')->alias(Joomla\CMS\Session\Session::class, 'session.cli')->alias(Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(Joomla\CMS\Application\SiteApplication::class); Joomla\CMS\Factory::$application = $app; $app->createExtensionNamespaceMap();
if ($app->get('db') !== 'formstudio_joomla' || $app->get('host') !== '127.0.0.1:13367') { throw new RuntimeException('Refusing non-isolated file fixture.'); }
$app->loadLanguage($container->get(Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('en-GB', false));
$users = $container->get(Joomla\CMS\User\UserFactoryInterface::class);
$credentials = json_decode(file_get_contents($root . '/build/joomla-test.json'), true, 512, JSON_THROW_ON_ERROR); $admin = $users->loadUserByUsername($credentials['username']); unset($credentials); $app->loadIdentity($admin);
$storage = $root . '/build/native-private-exports'; if (!is_dir($storage)) { mkdir($storage, 0770, true); }
$db = new Nicode\FormStudio\Infrastructure\Database\Connection($container->get(Joomla\Database\DatabaseInterface::class));
$extension = $db->row('SELECT extension_id, params FROM ' . $db->quote('#__extensions') . " WHERE type = 'component' AND element = 'com_nicode_form_studio'");
$params = new Joomla\Registry\Registry($extension['params']); $params->set('export_path', $storage);
$db->execute('UPDATE ' . $db->quote('#__extensions') . ' SET params = :params WHERE extension_id = :id', [':params' => $params->toString(), ':id' => (int) $extension['extension_id']]);
$runtime = new Joomla\DI\Container($container); $runtime->registerServiceProvider(new Nicode\FormStudio\Infrastructure\Joomla\RuntimeProvider($app, $params, $site));
$administration = $runtime->get(Nicode\FormStudio\Application\FormAdministration::class);
$form = $administration->create('Native jobs fixture', 'native-jobs-' . bin2hex(random_bytes(5)), (int) $admin->id);
$draft = $administration->edit($form, (int) $admin->id)['draft']; $field = Nicode\FormStudio\Domain\Uuid::create();
$draft['elements'] = [['uuid' => $field, 'type' => 'field']];
$draft['fields'] = [['uuid' => $field, 'name' => 'answer', 'type' => 'text', 'index' => true, 'config' => ['label' => 'Export answer', 'max_length' => 255]]];
$retryAction = Nicode\FormStudio\Domain\Uuid::create();
$draft['actions'] = [['uuid' => $retryAction, 'type' => 'redirect', 'enabled' => true, 'order' => 0, 'config' => ['url' => '/index.php'], 'failure_policy' => 'non_blocking']];
$revision = $administration->save($form, 0, $draft, (int) $admin->id); $version = $administration->publish($form, $revision, (int) $admin->id);
$spec = $runtime->get(Nicode\FormStudio\Infrastructure\Database\FormRepository::class)->version($form, $version);
$responses = []; $responseIds = [];
foreach (['matching native export', 'matching native export', 'other native answer'] as $answer) {
    $response = $runtime->get(Nicode\FormStudio\Infrastructure\Database\SubmissionRepository::class)->persist($form, $version, $spec, [$field => $answer], hash('sha256', random_bytes(32)));
    $responses[] = $response->uuid; $responseIds[] = $response->id;
}
if (!$runtime->get(Nicode\FormStudio\Registry\JobHandlerRegistry::class)->has('export-csv')) { throw new RuntimeException('Native export composition missing.'); }
$actionRuns = $runtime->get(Nicode\FormStudio\Infrastructure\Database\ActionRunRepository::class);
$seededFailure = $actionRuns->claim($responseIds[2], $retryAction, 'redirect'); $actionRuns->finish($seededFailure, 'failed', 'synthetic_failure');
$actionRuns->summarize($responseIds[2], 'partial_failure');
file_put_contents($root . '/build/native-job-fixture.json', json_encode(['form_id' => $form, 'version_id' => $version, 'field_uuid' => $field, 'responses' => $responses, 'response_ids' => $responseIds, 'retry_action' => $retryAction], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Isolated native jobs fixture and private export workspace configured.\n";

// Dedicated synthetic CSV cells; no actions or external delivery.
$csvForm=$administration->create('CSV formula acceptance','csv-formula-'.bin2hex(random_bytes(5)),(int)$admin->id);
$csvDraft=$administration->edit($csvForm,(int)$admin->id)['draft'];
$csvField=Nicode\FormStudio\Domain\Uuid::create(); $csvLabel='=1+1';
$csvDraft['elements']=[['uuid'=>$csvField,'type'=>'field']];
$csvDraft['fields']=[['uuid'=>$csvField,'name'=>'literal','type'=>'textarea','config'=>['label'=>$csvLabel]]];
$csvDraft['actions']=[];
$csvRevision=$administration->save($csvForm,0,$csvDraft,(int)$admin->id);
$csvVersion=$administration->publish($csvForm,$csvRevision,(int)$admin->id);
$csvSpec=$runtime->get(Nicode\FormStudio\Infrastructure\Database\FormRepository::class)->version($csvForm,$csvVersion);
$csvValues=['=1+1','+SUM(A1:A2)','-2+1','@SUM(A1)',"\t=1","\r=1","\n=1",' =1',"\xEF\xBB\xBF=1",'a,b',"line\r\nnext",'"quoted"',"normal á 日本", "'=already literal"];
$csvAnswers=[];
foreach($csvValues as $value) {
    $stored=$runtime->get(Nicode\FormStudio\Infrastructure\Database\SubmissionRepository::class)->persist($csvForm,$csvVersion,$csvSpec,[$csvField=>$value],hash('sha256',random_bytes(32)));
    $csvAnswers[]=['reference'=>$stored->uuid,'value'=>$value];
}
file_put_contents($root.'/build/native-csv-formula-fixture.json',json_encode(['form_id'=>$csvForm,'field_uuid'=>$csvField,'label'=>$csvLabel,'answers'=>$csvAnswers],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
