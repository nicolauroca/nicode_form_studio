<?php
declare(strict_types=1);

$categories = ['success', 'validation_error', 'session_error', 'anti_spam_rejected', 'rate_limited', 'upload_error', 'captcha_error', 'captcha_unavailable', 'captcha_required', 'persistence_error', 'unexpected_error', 'processing_pending', 'action_blocking_failure', 'action_partial_failure'];
$created = $api('create', ['name' => 'Native message acceptance', 'alias' => 'messages-http-' . bin2hex(random_bytes(6))]);
$id = $created['id'];
try {
    $record = $api('record', query: ['id' => $id]); $draft = $record['draft'];
    $field = 'dbdcfb79-852f-461a-bbab-942a72d1c8c4';
    $draft['elements'] = [['uuid' => $field, 'type' => 'field']];
    $draft['fields'] = [['uuid' => $field, 'name' => 'answer', 'type' => 'text', 'config' => ['required' => true]]];
    $draft['security'] = ['rate_limit' => 2, 'rate_window' => 60];
    foreach ($categories as $category) {
        $draft['post_submit']['messages'][$category] = 'Base ' . $category . ' {{form.name}}';
        $draft['translations']['es-ES']['messages'][$category] = 'Traducido ' . $category . ' {{form.name}}';
    }
    $draft['post_submit']['messages']['validation_error'] .= ' <script>message-marker</script>';
    $saved = $api('save', ['id' => $id, 'revision' => 0, 'draft' => $draft]);
    $published = $api('publish', ['id' => $id, 'revision' => $saved['revision']]);
    $record = $api('record', query: ['id' => $id]);
    $assert($record['draft']['post_submit']['messages'] == $draft['post_submit']['messages'] && $record['draft']['translations'] == $draft['translations'], 'Native message save/reload changed configured categories.');
    $editor = $request($base . '?option=com_nicode_form_studio&view=editor&id=' . $id);
    $assert($editor['status'] === 200, 'Native message editor failed.');
    $editorData = $dom($editor['body'])->query('//*[@data-nfs-editor-data]')->item(0);
    $assert($editorData instanceof DOMElement, 'Native message editor data missing.');
    $loaded = json_decode($editorData->textContent, true, 512, JSON_THROW_ON_ERROR);
    $assert(($loaded['draft']['post_submit']['messages'] ?? null) == $draft['post_submit']['messages'], 'Editor reload lost configured result messages.');
    $before = $api('preview', query: ['id' => $id, 'version' => $published['version_id'], 'locale' => 'es-ES']);
    foreach ($categories as $category) { $assert(str_contains($before['html'], 'Traducido ' . $category), 'Published localized preview omitted category ' . $category); }
    $siteRequest = static function (string $url, ?array $post = null) use ($jar): array {
        if (!str_starts_with($url, 'http://127.0.0.1:13371/index.php')) { throw new RuntimeException('Unexpected message-test public destination.'); }
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
        if ($post !== null) { curl_setopt($handle, CURLOPT_POST, true); curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $raw = curl_exec($handle); if (!is_string($raw)) { throw new RuntimeException('Public message-test connection failed.'); }
        $offset = curl_getinfo($handle, CURLINFO_HEADER_SIZE); curl_setopt($handle, CURLOPT_COOKIELIST, 'FLUSH');
        return ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $offset), 'body' => substr($raw, $offset)];
    };
    $public = $siteRequest('http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&tmpl=component&id=' . $id);
    $publicDom = $dom($public['body']); $publicForm = $publicDom->query('//form[@data-nfs-form]')->item(0);
    $assert($public['status'] === 200 && $publicForm instanceof DOMElement, 'Published message-test form unavailable.');
    $post = [];
    foreach ($publicDom->query('.//input[@type="hidden"]', $publicForm) as $input) { $post[$input->getAttribute('name')] = $input->getAttribute('value'); }
    $action = $publicForm->getAttribute('action');
    $url = str_starts_with($action, '/') ? 'http://127.0.0.1:13371' . $action : $action;
    $expectedMessage = 'Base validation_error Native message acceptance <script>message-marker</script>';
    foreach (['json', 'html'] as $format) {
        $post['format'] = $format;
        $invalidResponse = $siteRequest($url, $post);
        $assert($invalidResponse['status'] === 422 && str_contains(strtolower($invalidResponse['headers']), 'no-store'), 'Validation message transport returned wrong status/cache policy.');
        if ($format === 'json') {
            $result = json_decode($invalidResponse['body'], true, 512, JSON_THROW_ON_ERROR);
            $assert($result['accepted'] === false && $result['category'] === 'validation_error' && $result['message'] === $expectedMessage && isset($result['errors'][$field]), 'JSON validation rejected without the configured message and field error.');
        } else {
            $assert(str_contains($invalidResponse['body'], htmlspecialchars($expectedMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) && !str_contains($invalidResponse['body'], '<script>message-marker</script>'), 'HTML validation failed to escape the configured message.');
        }
    }
    foreach (['session_error' => 403, 'anti_spam_rejected' => 422, 'rate_limited' => 429] as $category => $expectedStatus) {
        foreach (['json', 'html'] as $format) {
            $rejectedPost = $post; $rejectedPost['format'] = $format;
            if ($category === 'session_error') { $rejectedPost['attempt'] = 'invalid-attempt'; }
            if ($category === 'anti_spam_rejected') { $rejectedPost['nfs_contact'] = 'bot-filled'; }
            $rejected = $siteRequest($url, $rejectedPost);
            $expected = 'Base ' . $category . ' Native message acceptance';
            $assert($rejected['status'] === $expectedStatus && str_contains(strtolower($rejected['headers']), 'no-store'), 'Configured rejection status/cache mismatch for ' . $category);
            if ($format === 'json') {
                $result = json_decode($rejected['body'], true, 512, JSON_THROW_ON_ERROR);
                $assert($result['accepted'] === false && $result['category'] === $category && $result['message'] === $expected, 'Configured JSON rejection message missing for ' . $category);
            } else { $assert(str_contains($rejected['body'], $expected), 'Configured HTML rejection message missing for ' . $category); }
        }
    }
    $draft['post_submit']['messages']['validation_eror'] = 'Mistyped category';
    $invalid = $api('save', ['id' => $id, 'revision' => $published['revision'], 'draft' => $draft]);
    $failed = $api('publish', ['id' => $id, 'revision' => $invalid['revision']], expected: 422);
    $diagnostics = array_filter($failed['diagnostics'] ?? [], static fn (array $item): bool => $item['code'] === 'post.message.category' && $item['path'] === '/post_submit/messages/validation_eror');
    $assert($failed['error'] === 'compilation_failed' && count($diagnostics) === 1, 'Native publication did not diagnose the mistyped category precisely.');
    $after = $api('record', query: ['id' => $id]);
    $assert((int) $after['form']['published_version_id'] === $published['version_id'] && (int) $after['form']['draft_revision'] === $invalid['revision'], 'Invalid message publication changed live version or draft revision.');
    $history = $api('history', query: ['id' => $id]);
    $assert(count($history['versions']) === 1, 'Invalid message publication created a historical version.');
    $assert((int) $history['versions'][0]['submission_count'] === 0, 'Rejected message cases persisted a response.');
    $historical = $api('preview', query: ['id' => $id, 'version' => $published['version_id'], 'locale' => 'es-ES']);
    foreach ($categories as $category) { $assert(str_contains($historical['html'], 'Traducido ' . $category), 'Invalid draft changed historical category ' . $category); }
    $assert(!str_contains($historical['html'], 'Mistyped category'), 'Invalid draft message leaked into historical preview.');
    echo "Native messages: all categories save/publish/reload/localize; validation, attempt, anti-spam and rate rejection in JSON/HTML; escaped text; zero submissions; typo preserves live version/history.\n";
} finally {
    $current = $api('record', query: ['id' => $id]);
    if ($current['form']['state'] === 'published') { $api('deactivate', ['id' => $id, 'revision' => (int) $current['form']['draft_revision'], 'state' => 'unpublished']); }
}
