<?php
declare(strict_types=1);

// Author a snapshot with a test-only provider, then render where it is unavailable.
$root = dirname(__DIR__); $site = $root . '/build/joomla-6.0.0';
$_SERVER['HTTP_HOST'] = '127.0.0.1:13371'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
define('_JEXEC', 1); define('JPATH_BASE', $site);
require $site . '/includes/defines.php'; require $site . '/includes/framework.php';
$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')->alias(Joomla\CMS\Session\Session::class, 'session.cli')->alias(Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(Joomla\CMS\Application\SiteApplication::class); Joomla\CMS\Factory::$application = $app;
if ($app->get('db') !== 'formstudio_joomla' || $app->get('host') !== '127.0.0.1:13367') { throw new RuntimeException('Refusing non-isolated failure fixture.'); }
$app->createExtensionNamespaceMap();
$credentials = json_decode(file_get_contents($root . '/build/joomla-test.json'), true, 512, JSON_THROW_ON_ERROR);
$admin = $container->get(Joomla\CMS\User\UserFactoryInterface::class)->loadUserByUsername($credentials['username']); unset($credentials); $app->loadIdentity($admin);
$runtime = $app->bootComponent('com_nicode_form_studio')->runtime($app);
$db = $runtime->get(Nicode\FormStudio\Infrastructure\Database\Connection::class);
$sources = new Nicode\FormStudio\Registry\DataSourceRegistry();
$sources->register(new class implements Nicode\FormStudio\Contract\DataSourceInterface {
    public function id(): string { return 'fixture.unavailable'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['cache' => false]; }
    public function validateConfiguration(array $configuration, string $path): array { return (new Nicode\FormStudio\DataSource\StaticDataSource())->validateConfiguration($configuration, $path); }
    public function options(array $configuration, array $inputs, array $trustedContext): array { return $configuration['options']; }
});
$compiler = new Nicode\FormStudio\Compiler\FormCompiler($runtime->get(Nicode\FormStudio\Registry\FieldTypeRegistry::class), $runtime->get(Nicode\FormStudio\Registry\ActionRegistry::class), $sources, $runtime->get(Nicode\FormStudio\Registry\ValidatorRegistry::class));
$forms = new Nicode\FormStudio\Infrastructure\Database\FormRepository($db, $compiler);
$id = $runtime->get(Nicode\FormStudio\Application\FormAdministration::class)->create('Unavailable provider acceptance', 'unavailable-provider-' . bin2hex(random_bytes(5)), (int) $admin->id);
$draft = $forms->draft($id); $uuid = Nicode\FormStudio\Domain\Uuid::create();
$draft['elements'] = [['uuid' => $uuid, 'type' => 'field']];
$draft['fields'] = [['uuid' => $uuid, 'name' => 'choice', 'type' => 'select', 'config' => ['label' => 'Private provider fixture label'], 'source' => ['type' => 'fixture.unavailable', 'config' => ['options' => [['value' => 'ES', 'label' => 'Spain']]]]]];
$revision = $forms->saveDraft($id, 0, $draft, (int) $admin->id); $forms->publish($id, $revision, (int) $admin->id);
$database = $container->get(Joomla\Database\DatabaseInterface::class);
$module = new Joomla\CMS\Table\Module($database, $container->get(Joomla\Event\DispatcherInterface::class));
$module->title = 'FormStudio isolated unavailable module'; $module->module = 'mod_nicode_form_studio'; $module->position = 'bottom-a'; $module->published = 1; $module->access = 1; $module->showtitle = 1; $module->client_id = 0; $module->language = '*'; $module->ordering = 3;
$module->params = json_encode(['form_id' => $id, 'cache' => 0], JSON_THROW_ON_ERROR);
if (!$module->check() || !$module->store()) { throw new RuntimeException('Failure module fixture could not be saved.'); }
$moduleId = (int) $module->id;
$request = static function (string $query): array {
    $handle = curl_init('http://127.0.0.1:13371/index.php?' . $query);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false]);
    $raw = curl_exec($handle); if (!is_string($raw)) { throw new RuntimeException('Failure fixture HTTP request failed.'); }
    $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    return ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $headerSize), 'body' => substr($raw, $headerSize)];
};
try {
    $assertAssets = static function(array $page, int $expected, int $forms): void {
        $document=new DOMDocument(); $prior=libxml_use_internal_errors(true); $document->loadHTML($page['body']); libxml_clear_errors(); libxml_use_internal_errors($prior); $xpath=new DOMXPath($document);
        $scripts=$xpath->query('//script[contains(@src,"/media/com_nicode_form_studio/js/formstudio.js")]')->length;
        $styles=$xpath->query('//link[contains(@href,"/media/com_nicode_form_studio/css/formstudio.css")]')->length;
        $instances=$xpath->query('//form[@data-nfs-form]')->length;
        foreach($xpath->query('//form[@data-nfs-form]') as $instance) {
            if($xpath->query('.//div[contains(concat(" ",normalize-space(@class)," ")," nfs-result ") and @role="status" and @aria-live="polite" and @tabindex="-1"]',$instance)->length!==1) { throw new RuntimeException('Native form lacks its own focusable live result region.'); }
        }
        if($scripts!==$expected || $styles!==$expected || $instances!==$forms || $xpath->query('//script[contains(@src,"/media/com_nicode_form_studio/js/admin.js")]')->length!==0) { throw new RuntimeException('Frontend assets were missing, duplicated or loaded without a rendered form.'); }
    };
    $empty=$request('option=com_users&view=login&tmpl=component');
    if($empty['status']!==200) { throw new RuntimeException('Unrelated native component unavailable.'); }
    $assertAssets($empty,0,0);
    $isolatedFailure=$request('option=com_nicode_form_studio&view=form&id='.$id.'&tmpl=component');
    if($isolatedFailure['status']!==503) { throw new RuntimeException('Isolated failure fixture did not fail safely.'); }
    $assertAssets($isolatedFailure,0,0);
    $database->setQuery('INSERT INTO #__modules_menu (moduleid, menuid) VALUES (' . $moduleId . ', 0)')->execute();
    $failure = $request('option=com_nicode_form_studio&view=form&id=' . $id);
    if ($failure['status'] !== 503 || !str_contains($failure['body'], 'temporarily unavailable') || str_contains($failure['body'], 'fixture.unavailable') || str_contains($failure['body'], 'Private provider fixture label') || !str_contains(strtolower($failure['headers']), 'no-store')) { throw new RuntimeException('Failed form leaked provider details or lacked safe unavailable response.'); }
    $baseline = json_decode(file_get_contents($root . '/build/joomla-runtime-results.json'), true, 512, JSON_THROW_ON_ERROR);
    $healthy = $request('option=com_nicode_form_studio&view=form&id=' . $baseline['form_id']);
    $assertAssets($healthy,1,3);
    $single=$request('option=com_nicode_form_studio&view=form&id='.$baseline['form_id'].'&tmpl=component');
    if($single['status']!==200) { throw new RuntimeException('Single form fixture unavailable.'); }
    $assertAssets($single,1,1);
    if ($healthy['status'] !== 200 || substr_count($healthy['body'], 'data-nfs-form=""') !== 3 || !str_contains($healthy['body'], 'temporarily unavailable')) { throw new RuntimeException('A failed module prevented healthy form instances from rendering.'); }
    $events = $db->rows('SELECT correlation_id FROM ' . $db->table('technical_log') . " WHERE event_type = 'form.render_failed' ORDER BY id DESC LIMIT 10");
    $matched = false;
    foreach ($events as $event) { if (str_contains($healthy['body'], $event['correlation_id'])) { $matched = true; } }
    if (!$matched) { throw new RuntimeException('Rendered failure reference was not logged.'); }
    $method = $request('option=com_nicode_form_studio&task=form.submit&format=json'); $result = json_decode($method['body'], true, 512, JSON_THROW_ON_ERROR);
    if ($method['status'] !== 405 || ($result['category'] ?? '') !== 'method_not_allowed' || !str_contains(strtolower($method['headers']), 'allow: post')) { throw new RuntimeException('Submission GET did not return the POST-only contract.'); }
    file_put_contents($root . '/build/native-public-failure-fixture.json', json_encode(['form_id' => $id, 'module_id' => $moduleId, 'assets_verified' => ['unrelated_component_none', 'failed_component_none', 'single_form_once', 'component_plus_modules_once']], JSON_THROW_ON_ERROR));
    echo "Native public failure boundaries: unavailable component 503, module isolation, safe correlated log and POST-only submission passed.\n";
} finally {
    $database->setQuery('UPDATE #__modules SET published = 0 WHERE id = ' . $moduleId)->execute();
}
