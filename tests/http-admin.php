<?php
declare(strict_types=1);

// End-to-end native administrator session; synthetic data on the isolated site only.
$root = dirname(__DIR__);
require $root . '/build/joomla-6.0.0/configuration.php'; $configuration = new JConfig();
if ($configuration->db !== 'formstudio_joomla' || $configuration->host !== '127.0.0.1:13367' || $configuration->live_site !== 'http://127.0.0.1:13371') { throw new RuntimeException('Refusing non-isolated administrator test.'); }
$base = 'http://127.0.0.1:13371/administrator/index.php';
$jar = $root . '/build/admin-cookie-' . bin2hex(random_bytes(8)) . '.txt';
$request = static function (string $url, ?array $post = null) use ($jar): array {
    if (!str_starts_with($url, 'http://127.0.0.1:13371/administrator/index.php')) { throw new RuntimeException('Unexpected administrator destination.'); }
    $handle = curl_init($url);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_PROXY => '', CURLOPT_HEADER => true]);
    if ($post !== null) { curl_setopt($handle, CURLOPT_POST, true); curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = curl_exec($handle);
    if (!is_string($raw)) { throw new RuntimeException('Administrator HTTP connection failed.'); }
    $offset = curl_getinfo($handle, CURLINFO_HEADER_SIZE); $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_setopt($handle, CURLOPT_COOKIELIST, 'FLUSH');
    return ['status' => $status, 'headers' => substr($raw, 0, $offset), 'body' => substr($raw, $offset)];
};
$dom = static function (string $html): DOMXPath {
    $document = new DOMDocument(); $before = libxml_use_internal_errors(true);
    try { $document->loadHTML($html); } finally { libxml_clear_errors(); libxml_use_internal_errors($before); }
    return new DOMXPath($document);
};
$assert = static function (bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } };
try {
    $login = $request($base . '?option=com_nicode_form_studio');
    $assert(!str_contains($login['body'], 'data-nfs-admin'), 'Anonymous visitor reached the administrator.');
    $xpath = $dom($login['body']); $post = [];
    foreach ($xpath->query('//form[.//input[@name="username"]]//input[@type="hidden"]') as $input) { $post[$input->getAttribute('name')] = $input->getAttribute('value'); }
    $credentials = json_decode(file_get_contents($root . '/build/joomla-test.json'), true, 512, JSON_THROW_ON_ERROR);
    $post['username'] = $credentials['username']; $post['passwd'] = $credentials['password'];
    $loginResult = $request($base, $post); unset($post, $credentials);
    $assert(in_array($loginResult['status'], [302, 303], true), 'Native administrator login failed.');
    $page = $request($base . '?option=com_nicode_form_studio&view=forms');
    $xpath = $dom($page['body']); $section = $xpath->query('//*[@data-nfs-admin]')->item(0);
    $assert($page['status'] === 200 && $section instanceof DOMElement, 'Native form list failed.');
    $token = $section->getAttribute('data-csrf');
    $assert($token !== '' && str_contains(strtolower($page['headers']), 'no-store'), 'Missing CSRF or cache control.');
    if (($argv[1] ?? '') === '--support-summary') { require __DIR__ . '/http-admin-support-summary.php'; return; }
    if (($argv[1] ?? '') === '--persistence-default') { require __DIR__ . '/http-admin-persistence-default.php'; return; }
    $api = static function (string $task, ?array $payload = null, array $query = [], int $expected = 200, bool $csrf = true) use ($base, $request, $token, $assert): array {
        $post = $payload === null ? null : ['payload' => json_encode($payload, JSON_THROW_ON_ERROR)] + ($csrf ? [$token => '1'] : []);
        $response = $request($base . '?' . http_build_query(['option' => 'com_nicode_form_studio', 'task' => str_contains($task, '.') ? $task : 'form.' . $task, 'format' => 'json'] + $query), $post);
        $assert($response['status'] === $expected, 'Unexpected status for ' . $task . ': ' . $response['status']);
        $assert(str_contains(strtolower($response['headers']), 'no-store'), 'Administrator response is cacheable.');
        $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        $assert(($decoded['ok'] ?? null) === ($expected === 200), 'Incorrect administrator result envelope.');
        return $decoded['data'] ?? $decoded;
    };
    if (($argv[1] ?? '') === '--jobs') { require __DIR__ . '/http-admin-jobs.php'; return; }
    if (($argv[1] ?? '') === '--reindex') { require __DIR__ . '/http-admin-reindex.php'; return; }
    if (($argv[1] ?? '') === '--messages') { require __DIR__ . '/http-admin-messages.php'; return; }
    if (($argv[1] ?? '') === '--repeated') { require __DIR__ . '/http-admin-repeated.php'; return; }
    if (($argv[1] ?? '') === '--navigation') { require __DIR__ . '/http-admin-navigation.php'; return; }
    if (($argv[1] ?? '') === '--success') { require __DIR__ . '/http-admin-success.php'; return; }
    if (($argv[1] ?? '') === '--csrf') { require __DIR__ . '/http-admin-csrf.php'; return; }
    $creation = ['name' => 'HTTP administrator fixture', 'alias' => 'admin-http-' . bin2hex(random_bytes(6)), 'actor' => 999999];
    require __DIR__ . '/http-admin-preview-options.php';
    $api('create', expected: 405);
    $assert($api('create', $creation, expected: 403, csrf: false)['error'] === 'session_error', 'Missing CSRF not rejected.');
    $created = $api('create', $creation); $id = $created['id'];
    if (($argv[1] ?? '') === '--responses') { require __DIR__ . '/http-admin-submissions.php'; return; }
    $edit = $api('record', query: ['id' => $id]);
    $assert((int) $edit['form']['created_by'] !== 999999, 'Browser controlled actor identity.');
    $editor = $request($base . '?option=com_nicode_form_studio&view=editor&id=' . $id);
    $assert($editor['status'] === 200 && str_contains($editor['body'], 'data-nfs-editor-data'), 'Installed visual editor missing.');
    $draft = $edit['draft'];
    $field = 'fad0ed49-a98c-4d5b-8a6a-2a62077f01a8';
    $draft['elements'] = [['uuid' => $field, 'type' => 'field', 'parent_uuid' => null]];
    $draft['fields'] = [['uuid' => $field, 'name' => 'answer', 'type' => 'text', 'config' => ['label' => 'Administrator HTTP answer', 'required' => true]]];
    $saved = $api('save', ['id' => $id, 'revision' => 0, 'draft' => $draft]);
    $assert($saved['revision'] === 1, 'Save did not advance revision.');
    $assert($api('save', ['id' => $id, 'revision' => 0, 'draft' => $draft], expected: 409)['error'] === 'concurrent_edit', 'Stale editor overwrote draft.');
    $published = $api('publish', ['id' => $id, 'revision' => 1, 'comment' => 'Synthetic HTTP verification']);
    $assert($published['revision'] === 2, 'Publication did not consume editor revision.');
    $preview = $api('preview', query: ['id' => $id]);
    $assert(str_contains($preview['html'], 'data-nfs-submit disabled') && str_contains($preview['html'], 'Administrator HTTP answer'), 'Preview differs from draft or permits submissions.');
    $draft['fields'][0]['type'] = 'missing-provider';
    $api('save', ['id' => $id, 'revision' => 2, 'draft' => $draft]);
    $failed = $api('publish', ['id' => $id, 'revision' => 3], expected: 422);
    $assert($failed['error'] === 'compilation_failed' && count($failed['diagnostics']) > 0, 'Invalid provider was published.');
    $current = $api('record', query: ['id' => $id]);
    $assert((int) $current['form']['published_version_id'] === $published['version_id'] && (int) $current['form']['draft_revision'] === 3, 'Failed publication changed active version.');
    $historicalPreview = $api('preview', query: ['id' => $id, 'version' => $published['version_id']]);
    $assert($historicalPreview['version_id'] === $published['version_id'] && str_contains($historicalPreview['html'], 'Administrator HTTP answer'), 'Historical preview used the invalid current draft.');
    $comparison = $api('compare', query: ['id' => $id, 'left' => $published['version_id'], 'right' => 0]);
    $assert(count($comparison['changes']) === 1 && $comparison['changes'][0]['path'] === '/fields/' . $field . '/type' && $comparison['changes'][0]['after'] === 'missing-provider', 'Version comparison lost stable field identity.');
    $other = $api('create', ['name' => 'Comparison boundary fixture', 'alias' => 'compare-boundary-' . bin2hex(random_bytes(6))]);
    $api('compare', query: ['id' => $other['id'], 'left' => $published['version_id'], 'right' => 0], expected: 404);
    $versions = $api('history', query: ['id' => $id]);
    $assert(count($versions['versions']) === 1, 'Failed publication left a version behind.');
    $assert($versions['versions'][0]['version_state'] === 'active' && $versions['versions'][0]['submission_count'] === 0, 'Version state or authorized response count missing.');
    $api('restore', ['id' => $id, 'revision' => 3, 'version_id' => $published['version_id']]);
    $api('deactivate', ['id' => $id, 'revision' => 4, 'state' => 'unpublished']);
    $current = $api('record', query: ['id' => $id]);
    $assert($current['form']['state'] === 'unpublished' && $current['draft']['fields'][0]['type'] === 'text', 'Restore or deactivation lost history.');
    $api('save', ['id' => $id, 'revision' => '5junk', 'draft' => $draft], expected: 422);
    $api('record', query: ['id' => $id . 'junk'], expected: 422);
    $settings = ['name' => $current['form']['name'], 'alias' => $current['form']['alias'], 'access' => 1, 'language' => '*', 'publish_up' => null, 'publish_down' => '2027-02-30 12:30:00'];
    $api('settings', ['id' => $id, 'revision' => 5, 'settings' => $settings], expected: 422);
    $settings['publish_down'] = '2027-01-01 12:30:00';
    $api('settings', ['id' => $id, 'revision' => 5, 'settings' => $settings]);
    $current = $api('record', query: ['id' => $id]);
    $assert((int) $current['form']['draft_revision'] === 6 && str_starts_with($current['form']['publish_down'], '2027-01-01 12:30:00'), 'Publication settings or UTC date validation failed.');
    $permissions = $api('permissions', query: ['id' => $id, 'group' => 2]);
    $permissionChange = ['id' => $id, 'revision' => 6, 'rules_hash' => $permissions['rules_hash'], 'group' => 2, 'rules' => ['formstudio.submissions.export' => false]];
    $api('applyPermissions', $permissionChange, expected: 403, csrf: false);
    $wrongHash = $permissionChange; $wrongHash['rules_hash'] = str_repeat('0', 64);
    $api('applyPermissions', $wrongHash, expected: 409);
    $api('applyPermissions', $permissionChange);
    $permissions = $api('permissions', query: ['id' => $id, 'group' => 2]);
    $assert($permissions['revision'] === 7 && $permissions['permissions']['formstudio.submissions.export']['direct'] === false && $permissions['permissions']['formstudio.submissions.export']['effective'] === false, 'Native permissions write or effective result failed.');
    $api('applyPermissions', ['id' => $id, 'revision' => 7, 'rules_hash' => $permissions['rules_hash'], 'group' => 2, 'rules' => ['core.admin' => true]], expected: 422);
    foreach (['archived' => 7, 'trashed' => 8, 'unpublished' => 9] as $state => $stateRevision) {
        $payload = ['id' => $id, 'revision' => $stateRevision, 'state' => $state];
        $api('deactivate', $payload, expected: 403, csrf: false);
        $api('deactivate', $payload);
        $api('deactivate', $payload, expected: 409);
        $inactive = $api('record', query: ['id' => $id]);
        $assert($inactive['form']['state'] === $state && (int) $inactive['form']['draft_revision'] === $stateRevision + 1, 'Archive/trash restoration lost the optimistic revision.');
        $assert((int) $inactive['form']['published_version_id'] === $published['version_id'] && $inactive['draft']['fields'][0]['type'] === 'text', 'Archive/trash restoration deleted draft or version ownership.');
        $assert(count($api('history', query: ['id' => $id])['versions']) === 1, 'Archive/trash restoration deleted version history.');
    }
    $auditPage = $request($base . '?' . http_build_query(['option' => 'com_nicode_form_studio', 'view' => 'audit', 'form_id' => $id, 'event_type' => 'form.deactivate', 'from' => gmdate('Y-m-d'), 'to' => gmdate('Y-m-d')]));
    $assert($auditPage['status'] === 200 && substr_count($auditPage['body'], '<td>form.deactivate</td>') === 4 && str_contains(strtolower($auditPage['headers']), 'no-store'), 'Native audit view lost deactivation history or cache protection.');
    foreach (['form_id' => '1junk', 'correlation_id' => 'invalid', 'from' => '2026-02-30'] as $key => $value) {
        $auditInvalid = $request($base . '?' . http_build_query(['option' => 'com_nicode_form_studio', 'view' => 'audit', $key => $value]));
        $assert($auditInvalid['status'] === 400, 'Malformed native audit filter was accepted.');
    }
    require __DIR__ . '/http-admin-submissions.php';
    require __DIR__ . '/http-admin-jobs.php';
    require __DIR__ . '/http-admin-optionsets.php';
    require __DIR__ . '/http-admin-duplication.php';
    require __DIR__ . '/http-admin-transfer.php';
    require __DIR__ . '/http-admin-translations.php';
    require __DIR__ . '/http-admin-templates.php';
    require __DIR__ . '/http-admin-validators.php';
    require __DIR__ . '/http-admin-source-resources.php';
    require __DIR__ . '/http-admin-form-deletion.php';
    require __DIR__ . '/http-admin-purge.php';
    require __DIR__ . '/http-admin-form-selection.php';
    require __DIR__ . '/http-admin-response-history.php';
    require __DIR__ . '/http-admin-search-order.php';
    require __DIR__ . '/http-admin-dashboard.php';
    require __DIR__ . '/http-admin-rate-limit.php';
    file_put_contents($root . '/build/admin-http-results.json', json_encode(['passed' => true, 'timestamp' => gmdate(DATE_ATOM), 'form_id' => $id, 'checks' => ['native admin authentication', 'anonymous exclusion', 'native CSRF', 'POST-only mutations', 'actor identity', 'list and editor rendering', 'draft persistence', 'optimistic conflict 409', 'compile and publish', 'preview disabled submission', 'failed publication preserves active version', 'history', 'restore', 'deactivation', 'strict request identities', 'UTC publication settings', 'native group ACL write/read', 'ACL CSRF and hash conflicts', 'historical preview', 'stable-UUID version comparison', 'cross-form version isolation']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Native administrator HTTP: authentication, CSRF, visual editor, save/conflict, publication diagnostics, preview, version history, restore and deactivation passed.\n";
} finally { if (is_file($jar)) { unlink($jar); } }
