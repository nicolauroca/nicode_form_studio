<?php
declare(strict_types=1);

// The requested forty-step scenario uses one form, installed Joomla routes,
// a real Joomla CAPTCHA adapter and captured MIME. No external mail is delivered.
$root = dirname(__DIR__); $site = $root . '/build/joomla-6.0.0';
require $site . '/configuration.php'; $configuration = new JConfig();
if ($configuration->db !== 'formstudio_joomla' || $configuration->host !== '127.0.0.1:13367' || $configuration->dbprefix !== 'j6_') { throw new RuntimeException('Non-isolated product fixture.'); }
$package = realpath($argv[1] ?? $root . '/build/development-package/pkg_nicode_form_studio.zip');
if (!$package || !str_starts_with(str_replace('\\', '/', $package), str_replace('\\', '/', $root) . '/') || !str_ends_with($package, '.zip')) { throw new RuntimeException('Expected repository-built package.'); }
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($site . '/cli/joomla.php') . ' extension:install --path=' . escapeshellarg($package) . ' --no-interaction', $installOutput, $installExit);
if ($installExit !== 0) { throw new RuntimeException('Product package installation failed.'); }
require $site . '/libraries/nicode_form_studio/autoload.php';
$pdo = new PDO('mysql:host=127.0.0.1;port=13367;dbname=formstudio_joomla;charset=utf8mb4', $configuration->user, $configuration->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$auth = json_decode(file_get_contents($root . '/build/upload-test.json'), true, flags: JSON_THROW_ON_ERROR);
$jar = $root . '/build/product-cookie-' . bin2hex(random_bytes(6)) . '.txt';
$request = static function (string $path, ?array $post = null, string $failure = '') use ($jar, $auth): array {
    if (str_starts_with($path, 'http://127.0.0.1:13371/')) { $path = substr($path, strlen('http://127.0.0.1:13371')); }
    if (!str_starts_with($path, '/index.php') && !str_starts_with($path, '/administrator/index.php')) { throw new RuntimeException('Unexpected product route.'); }
    $ch = curl_init('http://127.0.0.1:13407' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25, CURLOPT_PROXY => '', CURLOPT_HEADER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_HTTPHEADER => ['X-Test-Nonce: ' . $auth['nonce'], 'X-Test-Mail-Failure: ' . $failure]]);
    if ($post !== null) { curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]); }
    $raw = curl_exec($ch); if (!is_string($raw)) { throw new RuntimeException('Product fixture HTTP unavailable.'); }
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_setopt($ch, CURLOPT_COOKIELIST, 'FLUSH');
    return ['status' => $status, 'body' => substr($raw, $size), 'headers' => substr($raw, 0, $size)];
};
$dom = static function (string $html): DOMXPath {
    $doc = new DOMDocument(); $old = libxml_use_internal_errors(true); try { $doc->loadHTML($html); } finally { libxml_clear_errors(); libxml_use_internal_errors($old); } return new DOMXPath($doc);
};
$assert = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$checks = [1 => 'Exact package installed']; $formId = null; $layout = null; $imported = null; $token = '';
file_put_contents($root . '/build/product-mail-capture.jsonl', '');
$captures = static fn (): array => array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($root . '/build/product-mail-capture.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
try {
    $login = $request('/administrator/index.php'); $xp = $dom($login['body']); $post = [];
    foreach ($xp->query('//form[.//input[@name="username"]]//input[@type="hidden"]') as $input) { $post[$input->getAttribute('name')] = $input->getAttribute('value'); }
    $credentials = json_decode(file_get_contents($root . '/build/joomla-test.json'), true, flags: JSON_THROW_ON_ERROR); $post['username'] = $credentials['username']; $post['passwd'] = $credentials['password']; unset($credentials);
    $login = $request('/administrator/index.php', $post); unset($post);
    $assert(in_array($login['status'], [302, 303], true), 'Product administrator login failed.');
    $page = $request('/administrator/index.php?option=com_nicode_form_studio&view=forms'); $node = $dom($page['body'])->query('//*[@data-nfs-admin]')->item(0);
    $assert($page['status'] === 200 && $node instanceof DOMElement, 'Product Administrator unavailable.'); $token = $node->getAttribute('data-csrf'); $checks[2] = 'Native Administrator session';
    $api = static function (string $task, ?array $payload = null, array $query = [], int $expected = 200) use ($request, $token, $assert): array {
        $response = $request('/administrator/index.php?' . http_build_query(['option' => 'com_nicode_form_studio', 'task' => $task, 'format' => 'json'] + $query), $payload === null ? null : ['payload' => json_encode($payload, JSON_THROW_ON_ERROR), $token => '1']);
        $data = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        $assert($response['status'] === $expected && ($data['ok'] ?? null) === ($expected === 200), 'Product API ' . $task . ' failed: ' . $response['status'] . ' ' . json_encode($data));
        return $data['data'] ?? $data;
    };
    $formId = $api('form.create', ['name' => 'Product acceptance', 'alias' => 'product-acceptance-' . bin2hex(random_bytes(6))])['id']; $checks[3] = 'Form created through Administrator';
    $record = $api('form.record', query: ['id' => $formId]); $draft = $record['draft']; $fields = [];
    foreach (['answer', 'email', 'country', 'city', 'consent', 'group'] as $name) { $fields[$name] = Nicode\FormStudio\Domain\Uuid::create(); }
    $draft['elements'] = [['uuid' => $fields['group'], 'type' => 'group', 'config' => ['label' => 'Contact details']]];
    foreach (['answer', 'email', 'country', 'city', 'consent'] as $name) { $draft['elements'][] = ['uuid' => $fields[$name], 'type' => 'field', 'parent_uuid' => $name === 'answer' ? $fields['group'] : null]; }
    $set = $api('optionset.create', ['name' => 'Product countries'])['id'];
    $api('optionset.save', ['id' => $set, 'revision' => 0, 'name' => 'Product countries', 'options' => [['value' => 'ES', 'label' => 'Spain', 'default' => true], ['value' => 'FR', 'label' => 'France']]]);
    $source = $api('optionset.source', ['id' => $set, 'revision' => 1]);
    $draft['fields'] = [
        ['uuid' => $fields['answer'], 'name' => 'answer', 'type' => 'text', 'index' => true, 'config' => ['label' => 'Original answer', 'required' => true, 'min_length' => 3, 'max_length' => 255]],
        ['uuid' => $fields['email'], 'name' => 'email', 'type' => 'email', 'config' => ['label' => 'Email', 'required' => true]],
        ['uuid' => $fields['country'], 'name' => 'country', 'type' => 'select', 'source' => $source, 'config' => ['label' => 'Country', 'required' => true]],
        ['uuid' => $fields['city'], 'name' => 'city', 'type' => 'select', 'source' => ['type' => 'static', 'dependencies' => [$fields['country']], 'config' => ['options' => [['value' => 'MD', 'label' => 'Madrid', 'when' => [$fields['country'] => 'ES']], ['value' => 'PA', 'label' => 'Paris', 'when' => [$fields['country'] => 'FR']]]]], 'config' => ['label' => 'City', 'required' => true]],
        ['uuid' => $fields['consent'], 'name' => 'consent', 'type' => 'consent', 'config' => ['label' => 'I consent to this synthetic test', 'required' => true]],
    ];
    $draft['rules'] = [['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'when' => ['field' => $fields['country'], 'operator' => 'equals', 'value' => 'FR'], 'effects' => [['target' => $fields['group'], 'type' => 'hide']]]];
    $draft['security']['captcha'] = ['mode' => 'provider', 'provider' => 'fixture-product-captcha']; $draft['security']['minimum_seconds'] = 0; $draft['persistence']['mode'] = 'full';
    $actions = ['internal' => Nicode\FormStudio\Domain\Uuid::create(), 'receipt' => Nicode\FormStudio\Domain\Uuid::create()];
    $draft['actions'] = [
        ['uuid' => $actions['internal'], 'type' => 'email_notification', 'order' => 0, 'failure_policy' => 'non_blocking', 'config' => ['to' => ['team@example.test'], 'subject' => 'Internal {{submission.reference}}', 'email_format' => 'html', 'body_text' => '', 'body_html' => '<h2>Answers</h2><pre>{{response.summary}}</pre>']],
        ['uuid' => $actions['receipt'], 'type' => 'email_autoresponse', 'order' => 1, 'failure_policy' => 'non_blocking', 'config' => ['email_field' => $fields['email'], 'subject' => 'Receipt {{submission.reference}}', 'body_text' => 'Thank you {{field.' . $fields['answer'] . '.value}}']],
    ];
    $draft['post_submit'] = ['behavior' => 'hide', 'messages' => ['success' => 'Product acceptance received {{submission.reference}}']];
    $revision = $api('form.save', ['id' => $formId, 'revision' => 0, 'draft' => $draft])['revision'];
    $editor = $request('/administrator/index.php?option=com_nicode_form_studio&view=editor&id=' . $formId); $assert($editor['status'] === 200 && str_contains($editor['body'], 'data-nfs-editor-data'), 'Configured form editor unavailable.');
    foreach ([4 => 'Several fields', 5 => 'Select', 6 => 'Option Set', 7 => 'Dependent selects', 8 => 'Group', 9 => 'Conditional group rule', 10 => 'Validation', 11 => 'Consent', 12 => 'Joomla CAPTCHA provider', 13 => 'Full storage', 14 => 'Internal notification', 15 => 'Autoresponse', 16 => 'Configured success'] as $step => $label) { $checks[$step] = $label; }
    $version1 = $api('form.publish', ['id' => $formId, 'revision' => $revision])['version_id']; $checks[17] = 'First immutable version published';
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/product-layout.php') . ' ' . (int) $formId, $layoutOutput, $layoutExit);
    $assert($layoutExit === 0, 'Native menu/module fixture failed.'); $layout = json_decode(file_get_contents($root . '/build/product-layout.json'), true, flags: JSON_THROW_ON_ERROR); $checks[18] = 'Native menu item';
    $publicPath = '/index.php?option=com_nicode_form_studio&view=form&id=' . $formId . '&Itemid=' . $layout['menu_id'];
    $getForm = static function (int $index = 0, ?array $page = null) use ($request, $dom, $assert, $publicPath, $formId): array {
        $page ??= $request($publicPath); $xp = $dom($page['body']); $nodes = $xp->query('//form[@data-nfs-form]'); $matching = [];
        foreach ($nodes as $candidate) { if ($xp->query('.//input[@name="form_id"]', $candidate)->item(0)?->getAttribute('value') === (string) $formId) { $matching[] = $candidate; } }
        $node = $matching[$index] ?? null; $assert($page['status'] === 200 && $node instanceof DOMElement, 'Product public form unavailable.'); $post = [];
        foreach ($xp->query('.//input[@type="hidden"]', $node) as $input) { $post[$input->getAttribute('name')] = $input->getAttribute('value'); }
        return ['action' => $node->getAttribute('action'), 'post' => $post, 'html' => $page['body'], 'id' => $node->getAttribute('id'), 'count' => count($matching)];
    };
    $public = $getForm(); $assert(str_contains($public['html'], 'Synthetic CAPTCHA') && str_contains($public['html'], 'Original answer'), 'CAPTCHA/field composition missing.'); $checks[19] = 'Frontend through menu';
    $values = [$fields['answer'] => 'Product first response', $fields['email'] => 'visitor@example.test', $fields['country'] => 'ES', $fields['city'] => 'MD', $fields['consent'] => '1'];
    $submit = static function (array $form, array $values, string $captcha = 'fixture-valid', string $failure = '') use ($request): array {
        $response = $request($form['action'], $form['post'] + ['format' => 'json', 'nfs' => $values, 'formstudio_captcha' => $captcha], $failure);
        return [$response['status'], json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR)];
    };
    [$status, $denied] = $submit($public, $values, 'invalid'); $assert(!($denied['accepted'] ?? false) && $denied['category'] === 'captcha_error', 'Configured CAPTCHA did not reject invalid input.');
    $invalid = $values; $invalid[$fields['city']] = 'PA'; [$status, $denied] = $submit($getForm(), $invalid); $assert(!($denied['accepted'] ?? false) && $denied['category'] === 'validation_error', 'Dependent select accepted forged option.');
    $invalid = $values; $invalid[$fields['answer']] = 'x'; $invalid[$fields['consent']] = '0'; [$status, $denied] = $submit($getForm(), $invalid); $assert(!($denied['accepted'] ?? false), 'Validation/consent accepted invalid input.');
    [$status, $first] = $submit($getForm(), $values); $assert($status === 200 && ($first['processed'] ?? false) && str_contains($first['message'], 'Product acceptance received'), 'First product submission failed.'); $checks[20] = 'Public submission accepted';
    $find = static function (string $reference) use ($pdo, $formId): array { $q = $pdo->prepare('SELECT id,form_version_id,canonical_payload FROM j6_nicode_form_studio_submissions WHERE form_id=? AND uuid=?'); $q->execute([$formId, $reference]); return $q->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException('Product submission missing.'); };
    $firstRow = $find($first['reference']); $assert((int) $firstRow['form_version_id'] === $version1, 'First snapshot identity lost.'); $checks[21] = 'Canonical submission stored';
    $mail = $captures(); $assert(count($mail) === 2 && $mail[0]['to'][0][0] === 'team@example.test' && $mail[1]['to'][0][0] === 'visitor@example.test' && str_contains(base64_decode($mail[0]['mime']), 'Product first response'), 'Captured notification/autoresponse mismatch.'); $checks[22] = 'Both email actions captured as MIME';
    $hiddenValues = $values; $hiddenValues[$fields['country']] = 'FR'; $hiddenValues[$fields['city']] = 'PA'; $hiddenValues[$fields['answer']] = 'Forged hidden answer';
    [$status, $hiddenResult] = $submit($getForm(), $hiddenValues);
    $assert($status === 200 && ($hiddenResult['processed'] ?? false) && !array_key_exists($fields['answer'], json_decode($find($hiddenResult['reference'])['canonical_payload'], true)['values']), 'Conditional group retained forged hidden data.');
    $detail = $request('/administrator/index.php?' . http_build_query(['option' => 'com_nicode_form_studio', 'view' => 'submission', 'form_id' => $formId, 'id' => $firstRow['id']])); $assert($detail['status'] === 200 && str_contains($detail['body'], 'Product first response'), 'Native response detail missing.'); $checks[23] = 'Submission opened in Administrator';
    $query = ['filters' => ['form_id' => $formId, 'state' => 'new'], 'fields' => [['field' => $fields['answer'], 'operator' => 'equals', 'value' => 'Product first response']]];
    $found = $api('submission.search', query: ['query' => json_encode($query)]); $assert(count($found['rows']) === 1 && (int) $found['rows'][0]['id'] === (int) $firstRow['id'], 'Product indexed search/filter failed.'); $checks[24] = 'Indexed answer search'; $checks[25] = 'Metadata and value filters combined';
    $runJob = static function (int $id) use ($pdo, $formId, $api, $request, $token, $assert): array {
        for ($batch = 0; $batch < 20; $batch++) {
            $record = $api('job.record', query: ['id' => $id]); if ($record['state'] === 'completed') { return $record; }
            $assert(!in_array($record['state'], ['failed', 'cancelled'], true), 'Product job failed: ' . $record['state']);
            // Prioritize only this owned fixture job, leaving unrelated test jobs untouched.
            $q = $pdo->prepare("UPDATE j6_nicode_form_studio_jobs SET available_at='1000-01-01 00:00:00' WHERE id=? AND form_id=? AND state='pending'"); $q->execute([$id, $formId]);
            $response = $request('/administrator/index.php?option=com_nicode_form_studio&task=job.tick', [$token => '1']); $assert($response['status'] === 200, 'Native product worker failed.');
        }
        throw new RuntimeException('Product job did not finish in bounded batches.');
    };
    $export = $api('job.enqueue', ['type' => 'export-json', 'form_id' => $formId, 'fields' => [$fields['answer']], 'query' => $query])['id']; $runJob($export);
    $download = $request('/administrator/index.php?option=com_nicode_form_studio&task=job.download', ['id' => $export, $token => '1']); $rows = json_decode($download['body'], true, flags: JSON_THROW_ON_ERROR); $assert($download['status'] === 200 && count($rows) === 1 && $rows[0]['values'][$fields['answer']] === 'Product first response', 'Product export failed.'); $checks[26] = 'Filtered background export downloaded';
    $record = $api('form.record', query: ['id' => $formId]); $draft['fields'][0]['config']['label'] = 'Revised answer'; $revision = $api('form.save', ['id' => $formId, 'revision' => (int) $record['form']['draft_revision'], 'draft' => $draft])['revision']; $checks[27] = 'Form edited';
    $assert(str_contains($getForm()['html'], 'Original answer') && !str_contains($getForm()['html'], 'Revised answer'), 'Draft changed production.'); $checks[28] = 'Published version isolated from draft';
    $version2 = $api('form.publish', ['id' => $formId, 'revision' => $revision])['version_id']; $checks[29] = 'Second version published';
    $values[$fields['answer']] = 'Product second response'; [$status, $second] = $submit($getForm(), $values); $assert($status === 200 && ($second['processed'] ?? false), 'Second product submission failed.'); $checks[30] = 'Second submission';
    $assert((int) $find($first['reference'])['form_version_id'] === $version1 && (int) $find($second['reference'])['form_version_id'] === $version2 && $version1 !== $version2, 'Historical submission versions changed.'); $checks[31] = 'Both original versions preserved';
    $stale = $getForm(); $record = $api('form.record', query: ['id' => $formId]); $api('form.deactivate', ['id' => $formId, 'revision' => (int) $record['form']['draft_revision'], 'state' => 'unpublished']); $checks[32] = 'Form unpublished';
    [$status, $denied] = $submit($stale, $values); $assert(!($denied['accepted'] ?? false) && $status >= 400, 'Unpublished direct POST accepted.'); $checks[33] = 'Direct stale POST rejected';
    $record = $api('form.record', query: ['id' => $formId]); $api('form.publish', ['id' => $formId, 'revision' => (int) $record['form']['draft_revision']]);
    $modulePage = $request($publicPath); $component = $getForm(0, $modulePage); $module1 = $getForm(1, $modulePage); $module2 = $getForm(2, $modulePage);
    $assert($component['count'] === 3 && count(array_unique([$component['id'], $module1['id'], $module2['id']])) === 3, 'Component/module instance isolation failed.'); $checks[34] = 'Same form rendered in module'; $checks[35] = 'Shared component/module runtime';
    [$status, $moduleResult] = $submit($module1, $values); $assert($status === 200 && ($moduleResult['processed'] ?? false), 'First module submission failed.');
    [$status, $failed] = $submit($module2, $values, failure: 'disabled'); $assert($status === 200 && ($failed['accepted'] ?? false) && $failed['category'] === 'action_partial_failure', 'Second module did not persist definite action failure.'); $checks[36] = 'Two live independent module instances submitted'; $checks[37] = 'Definite email failure persisted';
    $failedRow = $find($failed['reference']); $beforeRetry = count($captures());
    $retry = $api('submission.retry', ['form_id' => $formId, 'id' => (int) $failedRow['id'], 'attempts' => json_encode([$actions['internal'] => 1])])['job_id']; $runJob($retry);
    $retried = $api('submission.record', query: ['form_id' => $formId, 'id' => $failedRow['id']]); $assert($retried['action_status'] === 'succeeded' && count($captures()) === $beforeRetry + 1, 'Retry repeated successful autoresponse or failed to recover.'); $checks[38] = 'Failed action retried without duplicate success';
    $record = $api('form.record', query: ['id' => $formId]); $liveVersion = (int) $record['form']['published_version_id']; $api('form.restore', ['id' => $formId, 'revision' => (int) $record['form']['draft_revision'], 'version_id' => $version1]);
    $restored = $api('form.record', query: ['id' => $formId]); $assert($restored['draft']['fields'][0]['config']['label'] === 'Original answer' && (int) $restored['form']['published_version_id'] === $liveVersion, 'Restore changed live version.'); $checks[39] = 'Historical version restored into draft';
    $definition = $api('definition.export', query: ['id' => $formId, 'mode' => 'portable']); $transfer = ['document' => json_encode($definition), 'choices' => ['policy' => 'duplicate', 'name' => 'Product imported copy', 'alias' => 'product-copy-' . bin2hex(random_bytes(6)), 'revision' => 0]];
    $preview = $api('definition.preview', $transfer); $assert($preview['can_import_draft'], 'Product import preview failed.'); $imported = $api('definition.import', $transfer + ['review_token' => $preview['review_token'], 'acknowledge_review' => true])['id'];
    $copy = $api('form.record', query: ['id' => $imported]); $assert($copy['form']['state'] === 'draft' && $copy['draft']['uuid'] !== $draft['uuid'] && count($copy['draft']['fields']) === 5, 'Portable import lost fields or shared identity.'); $checks[40] = 'Definition export/review/import';
    ksort($checks); $assert(count($checks) === 40, 'Product scenario has missing steps.');
    file_put_contents($root . '/build/product-acceptance-results.json', json_encode(['passed' => true, 'package' => basename($package), 'sha256' => hash_file('sha256', $package), 'form_id' => $formId, 'versions' => [$version1, $version2], 'references' => [$first['reference'], $second['reference'], $moduleResult['reference'], $failed['reference']], 'layout' => $layout, 'imported_form' => $imported, 'steps' => $checks, 'captcha' => 'Joomla test provider, not an external service', 'mail' => 'Captured MIME, no external delivery', 'timestamp' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Product acceptance: all 40 requested steps passed on one form with native Joomla routes, captured email and test CAPTCHA.\n";
} finally {
    if ($formId !== null && isset($api)) { $record = $api('form.record', query: ['id' => $formId]); if ($record['form']['state'] === 'published') { $api('form.deactivate', ['id' => $formId, 'revision' => (int) $record['form']['draft_revision'], 'state' => 'unpublished']); } }
    if ($layout !== null) {
        $q = $pdo->prepare('UPDATE j6_menu SET published=0 WHERE id=?'); $q->execute([$layout['menu_id']]);
        $q = $pdo->prepare('UPDATE j6_modules SET published=0 WHERE id=?'); foreach ($layout['module_ids'] as $module) { $q->execute([$module]); }
    }
    if (is_file($jar)) { unlink($jar); }
}
