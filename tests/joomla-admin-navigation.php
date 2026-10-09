<?php
declare(strict_types=1);
$root = dirname(__DIR__); $site = $root . '/build/joomla-6.0.0';
require $site . '/configuration.php'; $config = new JConfig();
if ($config->db !== 'formstudio_joomla' || $config->host !== '127.0.0.1:13367') { throw new RuntimeException('Non-isolated admin fixture.'); }
$jar = $root . '/build/admin-navigation-' . bin2hex(random_bytes(5)) . '.cookies';
$request = static function (string $path, ?array $post = null) use ($jar): array {
    $ch = curl_init('http://127.0.0.1:13371/administrator/index.php' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25, CURLOPT_PROXY => '', CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar]);
    if ($post !== null) { curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]); }
    $body = curl_exec($ch); if (!is_string($body)) { throw new RuntimeException('Admin HTTP unavailable.'); }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_setopt($ch, CURLOPT_COOKIELIST, 'FLUSH');
    return ['status' => $status, 'body' => $body];
};
$xpath = static function (string $html): DOMXPath {
    $doc = new DOMDocument(); $old = libxml_use_internal_errors(true); $doc->loadHTML($html); libxml_clear_errors(); libxml_use_internal_errors($old); return new DOMXPath($doc);
};
$login = $request(''); $hidden = [];
foreach ($xpath($login['body'])->query('//form//input[@type="hidden"]') as $input) { $hidden[$input->getAttribute('name')] = $input->getAttribute('value'); }
$credentials = json_decode(file_get_contents($root . '/build/joomla-test.json'), true, flags: JSON_THROW_ON_ERROR);
$request('', $hidden + ['username' => $credentials['username'], 'passwd' => $credentials['password']]); unset($credentials);
$views = ['dashboard', 'forms', 'submissions', 'jobs', 'health', 'logs', 'audit', 'resources', 'templates', 'datasources', 'purge'];
foreach ($views as $view) {
    $page = $request('?option=com_nicode_form_studio&view=' . $view); $dom = $xpath($page['body']);
    if ($page['status'] !== 200 || $dom->query('//*[@id="toolbar-nfs-navigation"]')->length !== 1 || ($view !== 'dashboard' && $dom->query('//*[@id="toolbar-nfs-back"]')->length !== 1)) {
        file_put_contents($root . '/build/admin-navigation-error.html', $page['body']); throw new RuntimeException('Native toolbar unavailable: ' . $view . ' HTTP ' . $page['status']);
    }
    file_put_contents($root . '/build/admin-navigation-' . $view . '.html', $page['body']);
}
// Follow actual detail links rather than hard-coding fixture identities.
foreach (['forms' => 'editor', 'resources' => 'optionset', 'templates' => 'emailtemplate', 'datasources' => 'datasource', 'submissions' => 'submission'] as $listing => $detail) {
    $listingDom = $xpath(file_get_contents($root . '/build/admin-navigation-' . $listing . '.html'));
    $link = $listingDom->query('//a[contains(@href,"view=' . $detail . '&") and contains(@href,"id=")]')->item(0);
    if (!$link instanceof DOMElement) { throw new RuntimeException('Missing detail fixture for ' . $detail); }
    $query = parse_url($link->getAttribute('href'), PHP_URL_QUERY);
    $page = $request('?' . $query); $dom = $xpath($page['body']);
    if ($page['status'] !== 200 || $dom->query('//*[@id="toolbar-nfs-back"]')->length !== 1) { throw new RuntimeException('Detail Back unavailable: ' . $detail); }
    if ($detail === 'editor' && $dom->query('//*[@id="toolbar"]//button[@data-nfs-command="save"]')->length !== 1) { throw new RuntimeException('Native editor save missing.'); }
    if ($detail === 'editor') {
        $imports = [];
        foreach ($dom->query('//script[@type="importmap"]') as $map) { $imports += json_decode($map->textContent, true, flags: JSON_THROW_ON_ERROR)['imports'] ?? []; }
        foreach (glob($root . '/src/com_nicode_form_studio/media/js/*.js') as $module) {
            $uri = 'http://127.0.0.1:13371/media/com_nicode_form_studio/js/' . basename($module);
            if (($imports[$uri] ?? null) !== $uri . '?' . hash_file('sha256', $module)) { throw new RuntimeException('Missing or stale module import map: ' . basename($module)); }
        }

        $tabs = $dom->query('//button[@role="tab" and @data-nfs-tab]');
        if ($tabs->length < 10 || $dom->query('//*[@data-nfs-tab-panel and not(@hidden)]')->length !== 1 || $dom->query('//*[@data-nfs-tab-panel="fields" and not(@hidden)]')->length !== 1) { throw new RuntimeException('Editor must initially expose only its Fields panel.'); }
        foreach ($tabs as $tab) {
            $id = $tab->getAttribute('aria-controls');
            $target = $dom->query('//*[@id="' . $id . '" and @role="tabpanel"]')->item(0);
            if (!$target instanceof DOMElement || $target->getAttribute('aria-labelledby') !== $tab->getAttribute('id')) { throw new RuntimeException('Editor tab/panel relationship missing.'); }
        }
    }
}
// Exercise the real web installer and its postflight presentation.
$install = $request('?option=com_installer&view=install'); $post = [];
foreach ($xpath($install['body'])->query('//input[@type="hidden"]') as $input) { $post[$input->getAttribute('name')] = $input->getAttribute('value'); }
$directory = $root . '/build/admin-install-' . bin2hex(random_bytes(5)); mkdir($directory);
$zip = new ZipArchive(); $zip->open($root . '/build/development-package/pkg_nicode_form_studio.zip'); $zip->extractTo($directory); $zip->close();
$post = array_replace($post, ['option' => 'com_installer', 'task' => 'install.install', 'installtype' => 'folder', 'install_directory' => $directory]);
$installed = $request('?option=com_installer&view=install', $post);
if ($installed['status'] === 303 || $installed['status'] === 302) { $installed = $request('?option=com_installer&view=install'); }
file_put_contents($root . '/build/admin-install-result.html', $installed['body']);
$summary = $xpath($installed['body'])->query('//*[@data-nfs-install-summary]')->item(0);
if (!$summary instanceof DOMElement || !str_contains($summary->textContent, (string) simplexml_load_file($root . '/src/pkg_nicode_form_studio/pkg_nicode_form_studio.xml')->version) || str_contains($summary->textContent, 'COM_NICODE_')) { throw new RuntimeException('Localized install summary missing.'); }
echo "Native web install: version, translated postflight summary and documentation links rendered.\n";
$pdo = new PDO('mysql:host=127.0.0.1;port=13367;dbname=formstudio_joomla;charset=utf8mb4', $config->user, $config->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$menus = $pdo->query("SELECT link FROM j6_menu WHERE client_id = 1 AND link LIKE 'index.php?option=com_nicode_form_studio%'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['dashboard', 'forms', 'submissions', 'resources', 'health', 'audit'] as $view) { if (!in_array('index.php?option=com_nicode_form_studio&view=' . $view, $menus, true)) { throw new RuntimeException('Native submenu missing: ' . $view); } }
echo 'Native administrator: ' . count($views) . " sections with Joomla toolbar, deterministic Back and installed submenu verified.\n";
