<?php
declare(strict_types=1);

// Render as an anonymous visitor, change availability through the real admin
// service, then submit the previously rendered form with its valid session/token.
$root = dirname(__DIR__); $site = $root . '/build/joomla-6.0.0';
$_SERVER['HTTP_HOST'] = '127.0.0.1:13371'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
define('_JEXEC', 1); define('JPATH_BASE', $site);
require $site . '/includes/defines.php'; require $site . '/includes/framework.php';
$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')->alias(Joomla\CMS\Session\Session::class, 'session.cli')->alias(Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(Joomla\CMS\Application\SiteApplication::class); Joomla\CMS\Factory::$application = $app;
if ($app->get('db') !== 'formstudio_joomla' || $app->get('host') !== '127.0.0.1:13367') { throw new RuntimeException('Refusing non-isolated POST fixture.'); }
$app->createExtensionNamespaceMap();
$credentials = json_decode(file_get_contents($root . '/build/joomla-test.json'), true, flags: JSON_THROW_ON_ERROR);
$admin = $container->get(Joomla\CMS\User\UserFactoryInterface::class)->loadUserByUsername($credentials['username']); unset($credentials); $app->loadIdentity($admin);
$runtime = $app->bootComponent('com_nicode_form_studio')->runtime($app);
$db = $runtime->get(Nicode\FormStudio\Infrastructure\Database\Connection::class);
$forms = $runtime->get(Nicode\FormStudio\Application\FormAdministration::class);
$repository = $runtime->get(Nicode\FormStudio\Infrastructure\Database\FormRepository::class);
$jar = $root . '/build/post-revalidation-' . bin2hex(random_bytes(6)) . '.cookie';
$request = static function (string $path, ?array $post = null) use ($jar): array {
    if (!str_starts_with($path, '/index.php')) { throw new RuntimeException('Unexpected fixture route.'); }
    $curl = curl_init('http://127.0.0.1:13371' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($post !== null) { curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]); }
    $raw = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_setopt($curl, CURLOPT_COOKIELIST, 'FLUSH');
    if (!is_string($raw)) { throw new RuntimeException('POST fixture HTTP transport failed.'); }
    return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
};
$render = static function (int $form, string $channel) use ($request): array {
    $page = $request('/index.php?option=com_nicode_form_studio&view=form&id=' . $form . ($channel === 'component' ? '&tmpl=component' : ''));
    if ($page['status'] !== 200 || $page['body'] === '') { throw new RuntimeException('Fixture render failed for ' . $channel . ': HTTP ' . $page['status']); }
    $dom = new DOMDocument(); $prior = libxml_use_internal_errors(true);
    try { $dom->loadHTML($page['body']); } finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
    $xpath = new DOMXPath($dom); $nodes = $xpath->query('//form[@data-nfs-form][input[@name="form_id" and @value="' . $form . '"]][input[@name="channel" and @value="' . $channel . '"]]'); $node = $nodes->item(0);
    if ($page['status'] !== 200 || !$node instanceof DOMElement) { throw new RuntimeException('Available fixture did not render.'); }
    $post = [];
    foreach ($xpath->query('.//input[@type="hidden"]', $node) as $input) { $post[$input->getAttribute('name')] = $input->getAttribute('value'); }
    $destination = $node->getAttribute('action');
    if (str_starts_with($destination, 'http')) { $destination = parse_url($destination, PHP_URL_PATH) . '?' . parse_url($destination, PHP_URL_QUERY); }
    return [$destination, $post];
};
$created = []; $modules = []; $cases = [];
$database = $container->get(Joomla\Database\DatabaseInterface::class);
try {
  foreach (['component', 'module'] as $channel) {
    foreach (['unpublished', 'archived', 'trashed', 'access', 'language', 'future_start', 'expired', 'new_version'] as $case) {
        $form = $forms->create('POST revalidation acceptance', 'post-revalidation-' . bin2hex(random_bytes(6)), (int) $admin->id); $created[] = $form;
        $draft = $forms->edit($form, (int) $admin->id)['draft']; $field = Nicode\FormStudio\Domain\Uuid::create();
        $draft['elements'] = [['uuid' => $field, 'type' => 'field']];
        $draft['fields'] = [['uuid' => $field, 'name' => 'answer', 'type' => 'text', 'index' => true, 'config' => ['required' => true, 'max_length' => 255]]];
        $draft['actions'] = []; $draft['security']['captcha'] = ['mode' => 'none']; $draft['security']['minimum_seconds'] = 0;
        $revision = $forms->save($form, 0, $draft, (int) $admin->id);
        $forms->publish($form, $revision, (int) $admin->id);
        if ($channel === 'module') {
            $module = new Joomla\CMS\Table\Module($database, $container->get(Joomla\Event\DispatcherInterface::class));
            $module->title = 'POST revalidation module'; $module->module = 'mod_nicode_form_studio'; $module->position = 'bottom-a'; $module->published = 1; $module->access = 1; $module->showtitle = 0; $module->client_id = 0; $module->language = '*';
            $module->params = json_encode(['form_id' => $form, 'cache' => 0], JSON_THROW_ON_ERROR);
            if (!$module->check() || !$module->store()) { throw new RuntimeException('POST fixture module creation failed.'); }
            $modules[] = (int) $module->id;
            $database->setQuery('INSERT INTO #__modules_menu (moduleid, menuid) VALUES (' . (int) $module->id . ', 0)')->execute();
        }
        [$destination, $post] = $render($form, $channel);
        $post['nfs'] = [$field => 'Private stale POST marker'];
        // Client assertions cannot restore permission or publication settings.
        $post += ['access' => 1, 'state' => 'published', 'language' => '*', 'publish_up' => '', 'publish_down' => ''];
        $row = $repository->get($form);
        $settings = ['name' => $row['name'], 'alias' => $row['alias'], 'access' => 1, 'language' => '*', 'publish_up' => null, 'publish_down' => null];
        if (in_array($case, ['unpublished', 'archived', 'trashed'], true)) {
            $forms->deactivate($form, (int) $row['draft_revision'], (int) $admin->id, $case);
        } elseif ($case === 'new_version') {
            $draft['fields'][0]['config']['label'] = 'New published definition';
            $revision = $forms->save($form, (int) $row['draft_revision'], $draft, (int) $admin->id);
            $forms->publish($form, $revision, (int) $admin->id);
        } else {
            $changed = $settings;
            match ($case) {
                'access' => $changed['access'] = 2,
                'language' => $changed['language'] = 'es-ES',
                'future_start' => $changed['publish_up'] = gmdate('Y-m-d H:i:s', time() + 86400),
                'expired' => $changed['publish_down'] = gmdate('Y-m-d H:i:s', time() - 86400),
            };
            $forms->settings($form, (int) $row['draft_revision'], $changed, (int) $admin->id);
        }
        foreach (['json', 'html'] as $format) {
            $response = $request($destination, array_replace($post, ['format' => $format]));
            if ($response['status'] !== 404 || !str_contains(strtolower($response['headers']), 'no-store') || str_contains($response['body'], 'Private stale POST marker')) { throw new RuntimeException('Stale POST was accepted or exposed its payload: ' . $case . '/' . $format); }
            if ($format === 'json') {
                $result = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
                if (($result['accepted'] ?? null) !== false || ($result['category'] ?? '') !== 'form_unavailable') { throw new RuntimeException('Stale AJAX POST returned the wrong rejection category.'); }
            } elseif (!str_contains($response['body'], 'This form is unavailable.')) { throw new RuntimeException('Traditional stale POST omitted its localized rejection.'); }
            foreach (['submissions', 'submission_index', 'upload_staging'] as $table) {
                if ($db->rows('SELECT id FROM ' . $db->table($table) . ' WHERE form_id = :form', [':form' => $form]) !== []) { throw new RuntimeException('Rejected POST persisted data: ' . $table); }
            }
        }
        // Positive control: restore current availability, render a fresh token and
        // demonstrate that rejection was not caused by broken CSRF or transport.
        $row = $repository->get($form);
        $revision = $forms->settings($form, (int) $row['draft_revision'], $settings, (int) $admin->id);
        $forms->publish($form, $revision, (int) $admin->id);
        [$destination, $fresh] = $render($form, $channel); $fresh['nfs'] = [$field => 'Fresh accepted control']; $fresh['format'] = 'json';
        $forged = array_replace($fresh, ['channel' => $channel === 'component' ? 'module' : 'component']);
        $rejection = $request($destination, $forged); $result = json_decode($rejection['body'], true, flags: JSON_THROW_ON_ERROR);
        if ($rejection['status'] !== 403 || ($result['category'] ?? '') !== 'session_error' || $db->rows('SELECT id FROM ' . $db->table('submissions') . ' WHERE form_id = :form', [':form' => $form]) !== []) { throw new RuntimeException('Rendered attempt accepted a forged channel.'); }
        $response = $request($destination, $fresh); $result = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        if ($response['status'] !== 200 || !($result['accepted'] ?? false) || count($db->rows('SELECT id FROM ' . $db->table('submissions') . ' WHERE form_id = :form', [':form' => $form])) !== 1) { throw new RuntimeException('Fresh positive control failed after ' . $case); }
        $stored = $db->row('SELECT channel FROM ' . $db->table('submissions') . ' WHERE form_id = :form', [':form' => $form]);
        if ($stored['channel'] !== $channel) { throw new RuntimeException('Submission lost its verified channel.'); }
        $cases[] = ['case' => $case, 'channel' => $channel, 'form_id' => $form, 'ajax' => 404, 'traditional' => 404, 'forged_channel' => 403, 'fresh' => 200];
        if ($channel === 'module') { $module->published = 0; $module->store(); }
    }
  }
    require __DIR__ . '/http-post-user-revalidation.php';
    file_put_contents($root . '/build/native-post-revalidation-results.json', json_encode(['passed' => true, 'timestamp' => gmdate(DATE_ATOM), 'cases' => $cases, 'authenticated_acl' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Native POST revalidation: eight availability/version changes in component and real module channels, 32 stale POST rejections, 16 forged-channel rejections and 16 fresh controls with verified stored channel passed.\n";
} finally {
    foreach ($modules as $moduleId) {
        $database->setQuery('DELETE FROM #__modules_menu WHERE moduleid = ' . $moduleId)->execute();
        $module = new Joomla\CMS\Table\Module($database, $container->get(Joomla\Event\DispatcherInterface::class)); $module->delete($moduleId);
    }
    foreach ($created as $form) { $row = $repository->get($form); $forms->deactivate($form, (int) $row['draft_revision'], (int) $admin->id, 'unpublished'); }
    if (is_file($jar)) { unlink($jar); }
}
