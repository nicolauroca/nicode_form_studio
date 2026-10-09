<?php
declare(strict_types=1);
// Install/enable only an authored synthetic plugin in the named disposable lifecycle site.
$root = dirname(__DIR__); $site = $root . '/build/joomla-lifecycle';
require $site . '/configuration.php'; $configuration = new JConfig();
if ($configuration->db !== 'formstudio_lifecycle' || $configuration->dbprefix !== 'lc_' || $configuration->host !== '127.0.0.1:13367') { throw new RuntimeException('Refusing non-isolated provider fixture.'); }
$zipPath = $root . '/build/provider-fixture.zip'; $zip = new ZipArchive(); $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$fixture = __DIR__ . '/fixtures/plg_formstudio_providerfixture';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile()) { $zip->addFile($file->getPathname(), str_replace('\\', '/', substr($file->getPathname(), strlen($fixture) + 1))); }
}
$zip->close();
foreach ([$root . '/build/development-package/pkg_nicode_form_studio.zip', $zipPath] as $archive) {
    $output = []; $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($site . '/cli/joomla.php') . ' extension:install --path=' . escapeshellarg($archive) . ' 2>&1', $output, $code);
    if ($code !== 0) { throw new RuntimeException('Native provider fixture installation failed: ' . implode("\n", $output)); }
}
$pdo = new PDO('mysql:host=127.0.0.1;port=13367;dbname=formstudio_lifecycle;charset=utf8mb4', $configuration->user, $configuration->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("UPDATE lc_extensions SET enabled = 1 WHERE type = 'plugin' AND folder = 'formstudio' AND element = 'providerfixture'");
$_SERVER['HTTP_HOST'] = '127.0.0.1:13371'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
define('_JEXEC', 1); define('JPATH_BASE', $site);
require $site . '/includes/defines.php'; require $site . '/includes/framework.php';
$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')->alias(Joomla\CMS\Session\Session::class, 'session.cli')->alias(Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(Joomla\CMS\Application\SiteApplication::class); Joomla\CMS\Factory::$application = $app;
$app->createExtensionNamespaceMap();
$app->loadLanguage($container->get(Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('en-GB', false));
$app->loadDocument(new Joomla\CMS\Document\HtmlDocument());
$credentials = json_decode(file_get_contents($root . '/build/joomla-lifecycle-test.json'), true, 512, JSON_THROW_ON_ERROR);
$admin = $container->get(Joomla\CMS\User\UserFactoryInterface::class)->loadUserByUsername($credentials['username']); unset($credentials); $app->loadIdentity($admin);
try {
    $runtime = $app->bootComponent('com_nicode_form_studio')->runtime($app);
    $sources = $runtime->get(Nicode\FormStudio\Registry\DataSourceRegistry::class);
    if (!$sources->has('joomla.categories') || !$sources->has('joomla.articles') || !$runtime->get(Nicode\FormStudio\Contract\CacheInterface::class) instanceof Nicode\FormStudio\Infrastructure\Joomla\SourceCache) { throw new RuntimeException('Native entity providers or Joomla source cache composition missing.'); }
    if (!$sources->has('fixture.department') || $sources !== $runtime->get(Nicode\FormStudio\Registry\DataSourceRegistry::class)) { throw new RuntimeException('Installed Joomla plugin not discovered once per runtime.'); }
    $provider = $sources->get('fixture.department');
    if (!str_contains(str_replace('\\', '/', (new ReflectionClass($provider))->getFileName()), '/build/joomla-lifecycle/plugins/formstudio/providerfixture/')) { throw new RuntimeException('Provider did not originate in installed plugin.'); }
    $result = $runtime->get(Nicode\FormStudio\DataSource\OptionResolver::class)->resolve(['type' => 'fixture.department', 'config' => ['prefix' => 'Fixture']], []);
    if ($result !== [['value' => 'support', 'label' => 'Fixture Support']]) { throw new RuntimeException('Custom provider did not execute through runtime resolver.'); }
    try { $sources->register($provider); throw new RuntimeException('Runtime source registry remained writable.'); } catch (LogicException) {}
    $renderers = $runtime->get(Nicode\FormStudio\Rendering\FieldRendererRegistry::class);
    try { $renderers->register('late', new Nicode\FormStudio\Rendering\CoreFieldRenderer()); throw new RuntimeException('Runtime renderer registry remained writable.'); } catch (LogicException) {}
    $search = $runtime->get(Nicode\FormStudio\Registry\SearchProviderRegistry::class);
    if (!$search->has('sql')) { throw new RuntimeException('Core search provider registration missing.'); }
    $forms = $runtime->get(Nicode\FormStudio\Application\FormAdministration::class);
    $formId = $forms->create('Provider lifecycle fixture', 'provider-' . bin2hex(random_bytes(6)), (int) $admin->id);
    $draft = $forms->edit($formId, (int) $admin->id)['draft']; $field = Nicode\FormStudio\Domain\Uuid::create();
    $draft['elements'] = [['uuid' => $field, 'type' => 'field', 'parent_uuid' => null]];
    $draft['fields'] = [['uuid' => $field, 'name' => 'department', 'type' => 'select', 'config' => ['label' => 'Department'], 'source' => ['type' => 'fixture.department', 'config' => ['prefix' => 'Fixture']]]];
    $revision = $forms->save($formId, 0, $draft, (int) $admin->id);
    $version = $forms->publish($formId, $revision, (int) $admin->id);
    $spec = $runtime->get(Nicode\FormStudio\Infrastructure\Database\FormRepository::class)->version($formId, $version);
    if (($spec->toArray()['provider_dependencies']['sources']['fixture.department'] ?? null) !== '1.0.0') { throw new RuntimeException('Published form did not pin custom provider.'); }
    $runtime->get(Nicode\FormStudio\Registry\ProviderDependencies::class)->assert($spec);
    $browserDefinition = $spec->toArray();
    $browserDefinition['fields'][0]['type'] = 'fixture.upper'; unset($browserDefinition['fields'][0]['source']);
    $browserDefinition['rules'] = [['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'when' => ['group' => 'AND', 'children' => [['field' => $field, 'operator' => 'fixture.suffix', 'value' => 'port']]], 'effects' => [['target' => $field, 'type' => 'required']]]];
    $browserCompiled = $runtime->get(Nicode\FormStudio\Compiler\FormCompiler::class)->compile($browserDefinition);
    if (!$browserCompiled->successful()) { throw new RuntimeException('Custom field/operator fixture failed compilation.'); }
    $browserSpec = $browserCompiled->spec;
    $publicBrowser = $runtime->get(Nicode\FormStudio\Rendering\PublicSpec::class)->project($browserSpec);
    $descriptor = $publicBrowser['browser_providers']['operators']['fixture.suffix'] ?? [];
    if (($descriptor['version'] ?? '') !== '1.2.0' || !str_contains($descriptor['module'] ?? '', '/media/plg_formstudio_providerfixture/js/fixture.js')) { throw new RuntimeException('Installed browser provider asset did not resolve through Joomla WAM.'); }
    if (!str_contains($publicBrowser['browser_providers']['fields']['fixture.upper']['styles'][0] ?? '', '/media/plg_formstudio_providerfixture/css/fixture.css')) { throw new RuntimeException('Installed custom field stylesheet missing.'); }
    if ($runtime->get(Nicode\FormStudio\Registry\FieldTypeRegistry::class)->get('fixture.upper')->normalize('  custom  ', []) !== 'CUSTOM') { throw new RuntimeException('Installed custom field normalizer missing.'); }
    $runtime->get(Nicode\FormStudio\Application\PublicationReadiness::class)->assert($browserSpec);
    $app->getDocument()->getWebAssetManager()->getRegistry()->remove('script', 'fixture.browser');
    try { $runtime->get(Nicode\FormStudio\Application\PublicationReadiness::class)->assert($browserSpec); throw new RuntimeException('Publication accepted missing browser asset.'); }
    catch (Nicode\FormStudio\Compiler\CompilationException) {}
    $app->getDocument()->getWebAssetManager()->registerScript('fixture.browser', 'plg_formstudio_providerfixture/fixture.js', ['version' => '1.2.0'], ['type' => 'module']);
    echo "Native browser provider: installed module resolution, nested dependency pin and missing-asset publication rejection verified.\n";
    $visitor = new Nicode\FormStudio\Submission\RequestContext(0, [1], 'en-GB', 'native-lifecycle-events', hash('sha256', random_bytes(32)), true);
    $rendered = $runtime->get(Nicode\FormStudio\Application\FormDisplay::class)->render($formId, $visitor, 'nfs-native-events', '/index.php', 'fixture_csrf');
    if (!str_contains($rendered['html'], 'Fixture Support')) { throw new RuntimeException('Native lifecycle render failed.'); }
    $attempt = $runtime->get(Nicode\FormStudio\Security\AttemptTokens::class)->issue($formId, $version, 'native-lifecycle-events:component');
    $eventResponse = $runtime->get(Nicode\FormStudio\Application\SubmissionPipeline::class)->submit(new Nicode\FormStudio\Submission\SubmitRequest($formId, $version, $attempt, [$field => 'support']), $visitor);
    if (!$eventResponse['accepted']) { throw new RuntimeException('Native lifecycle submission failed.'); }
    $portable = $runtime->get(Nicode\FormStudio\Application\FormExchange::class)->export($formId, 0, (int) $admin->id, 'portable');
    if (($portable['definition']['fields'][0]['source']['config']['prefix'] ?? null) !== 'Fixture') { throw new RuntimeException('Native portable provider contract failed.'); }
    $observed = array_column(NicodeFixture\Plugin\FormStudios\ProviderFixture\Extension\ProviderFixture::$lifecycle, 'phase');
    foreach (['ResolveDataSource', 'BeforeFormRender', 'AfterFormRender', 'BeforeValidation', 'AfterValidation', 'BeforeSubmissionPersist', 'AfterSubmissionPersist', 'BeforeExport', 'AfterExport'] as $phase) {
        if (!in_array($phase, $observed, true)) { throw new RuntimeException('Native plugin lifecycle phase missing: ' . $phase); }
    }
    echo "Installed native plugin lifecycle: render, resolution, validation, persistence and definition export events observed; custom portability verified.\n";
    $selectedRuntime = new Joomla\DI\Container($container);
    $selectedRuntime->registerServiceProvider(new Nicode\FormStudio\Infrastructure\Joomla\RuntimeProvider($app, new Joomla\Registry\Registry(['search_provider' => 'fixture.search']), JPATH_ROOT));
    $selectedSearch = $selectedRuntime->get(Nicode\FormStudio\Contract\SearchProviderInterface::class);
    if ($selectedSearch->id() !== 'fixture.search') { throw new RuntimeException('Native configured search provider ignored.'); }
    $selectedPage = $selectedRuntime->get(Nicode\FormStudio\Application\SubmissionExplorer::class)->page((int) $admin->id, new Nicode\FormStudio\Search\SearchRequest(['form_id' => $formId]));
    if (count($selectedPage['rows']) !== 1 || $selectedPage['rows'][0]['uuid'] !== $eventResponse['reference']) { throw new RuntimeException('Native explorer did not use configured search provider.'); }
    $jobAdministration = $selectedRuntime->get(Nicode\FormStudio\Application\JobAdministration::class);
    $searchJob = $jobAdministration->enqueue((int) $admin->id, $formId, 'submission-bulk', ['operation' => 'state', 'state' => 'new']);
    $jobRecord = $selectedRuntime->get(Nicode\FormStudio\Infrastructure\Database\Connection::class)->row('SELECT parameters FROM #__nicode_form_studio_jobs WHERE id = :id', [':id' => $searchJob]);
    if ((json_decode($jobRecord['parameters'], true)['search_provider']['id'] ?? null) !== 'fixture.search') { throw new RuntimeException('Native job failed to pin the configured search provider.'); }
    $jobAdministration->cancel((int) $admin->id, $searchJob);
    $unavailableRuntime = new Joomla\DI\Container($container);
    $unavailableRuntime->registerServiceProvider(new Nicode\FormStudio\Infrastructure\Joomla\RuntimeProvider($app, new Joomla\Registry\Registry(['search_provider' => 'missing.search']), JPATH_ROOT));
    if (!$unavailableRuntime->get(Nicode\FormStudio\Contract\SearchProviderInterface::class) instanceof Nicode\FormStudio\Search\UnavailableSearch || !$unavailableRuntime->get(Nicode\FormStudio\Registry\JobHandlerRegistry::class)->has('retention')) { throw new RuntimeException('Unavailable search blocked unrelated cleanup/retention handlers.'); }
    echo "Native configured search: plugin-backed explorer, job provider pin and missing-provider isolation verified.\n";
    $customId = $forms->create('Custom browser field fixture', 'browser-provider-' . bin2hex(random_bytes(6)), (int) $admin->id);
    $customDraft = $forms->edit($customId, (int) $admin->id)['draft'];
    $customField = Nicode\FormStudio\Domain\Uuid::create();
    $customDraft['elements'] = [['uuid' => $customField, 'type' => 'field', 'parent_uuid' => null]];
    $customDraft['fields'] = [['uuid' => $customField, 'name' => 'custom_name', 'type' => 'fixture.upper', 'index' => true, 'config' => ['label' => 'Custom uppercase name', 'required' => true]]];
    $customRevision = $forms->save($customId, 0, $customDraft, (int) $admin->id);
    $customVersion = $forms->publish($customId, $customRevision, (int) $admin->id);
    $customRendered = $runtime->get(Nicode\FormStudio\Application\FormDisplay::class)->render($customId, $visitor, 'nfs-custom-browser', '/index.php', 'fixture_csrf');
    if (!str_contains($customRendered['html'], 'fixture-uppercase') || !str_contains($customRendered['html'], 'fixture.js')) { throw new RuntimeException('Custom native renderer or browser manifest missing.'); }
    $customAttempt = $runtime->get(Nicode\FormStudio\Security\AttemptTokens::class)->issue($customId, $customVersion, 'native-lifecycle-events:component');
    $customResponse = $runtime->get(Nicode\FormStudio\Application\SubmissionPipeline::class)->submit(new Nicode\FormStudio\Submission\SubmitRequest($customId, $customVersion, $customAttempt, [$customField => '  custom value  ']), $visitor);
    if (!$customResponse['accepted']) { throw new RuntimeException('Custom field native submission failed.'); }
    $customRow = $runtime->get(Nicode\FormStudio\Infrastructure\Database\Connection::class)->row('SELECT id, canonical_payload FROM #__nicode_form_studio_submissions WHERE form_id = :form AND uuid = :uuid', [':form' => $customId, ':uuid' => $customResponse['reference']]);
    if ((json_decode($customRow['canonical_payload'], true)['values'][$customField] ?? null) !== 'CUSTOM VALUE') { throw new RuntimeException('Custom field canonical serialization failed.'); }
    $customIndex = $runtime->get(Nicode\FormStudio\Infrastructure\Database\Connection::class)->row('SELECT value_keyword FROM #__nicode_form_studio_submission_index WHERE submission_id = :submission AND field_uuid = :field', [':submission' => (int) $customRow['id'], ':field' => $customField]);
    if (($customIndex['value_keyword'] ?? null) !== 'CUSTOM VALUE') { throw new RuntimeException('Custom field typed index projection failed.'); }
    $customExport = $runtime->get(Nicode\FormStudio\Application\FormExchange::class)->export($customId, 0, (int) $admin->id, 'portable');
    if (($customExport['definition']['fields'][0]['config']['label'] ?? null) !== 'Custom uppercase name') { throw new RuntimeException('Custom field portable configuration missing.'); }
    file_put_contents($root . '/build/native-provider-browser.json', json_encode(['form_id' => $customId, 'version' => $customVersion, 'field_uuid' => $customField], JSON_THROW_ON_ERROR));
    echo "Custom field native acceptance: renderer, publication, canonical uppercase value, typed index and portable configuration passed.\n";
    $pdo->exec("UPDATE lc_extensions SET enabled = 0 WHERE type = 'plugin' AND folder = 'formstudio' AND element = 'providerfixture'");
    $output = []; $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/joomla-provider-unavailable.php') . ' ' . $formId . ' ' . $version . ' 2>&1', $output, $code);
    if ($code !== 0) { throw new RuntimeException('Provider removal boundary failed: ' . implode("\n", $output)); }
    echo implode("\n", $output) . "\n";
    file_put_contents($root . '/build/native-provider-results.json', json_encode(['passed' => true, 'timestamp' => gmdate(DATE_ATOM), 'provider' => $provider->id(), 'version' => $provider->version(), 'checks' => ['native plugin install and import', 'typed event', 'installed provider execution', 'request-shared registry', 'source and renderer freeze', 'core search registry']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Native Joomla provider plugin: installed discovery, typed event, custom source resolution and frozen registries verified.\n";
} finally {
    $fixtureEnabled = in_array('--keep-browser-fixture', $argv, true) ? 1 : 0;
    $pdo->exec("UPDATE lc_extensions SET enabled = " . $fixtureEnabled . " WHERE type = 'plugin' AND folder = 'formstudio' AND element = 'providerfixture'");
}
