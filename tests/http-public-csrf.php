<?php
declare(strict_types=1);

// Included after native admin login; all public requests use separate guest jars.
(static function () use ($api, $assert, $dom, $root, $pdo): void {
    $form = $api('create', ['name' => 'Public CSRF acceptance', 'alias' => 'public-csrf-' . bin2hex(random_bytes(6))])['id'];
    $jars = [$root . '/build/csrf-guest-a-' . bin2hex(random_bytes(6)) . '.txt', $root . '/build/csrf-guest-b-' . bin2hex(random_bytes(6)) . '.txt'];
    $http = static function (string $query, int $guest, ?array $post = null, array $headers = []) use ($jars): array {
        $curl = curl_init('http://127.0.0.1:13371/index.php?' . $query);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jars[$guest], CURLOPT_COOKIEFILE => $jars[$guest], CURLOPT_HTTPHEADER => $headers]);
        if ($post !== null) { curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $raw = curl_exec($curl); if (!is_string($raw)) { throw new RuntimeException('Public CSRF connection failed.'); }
        $offset = curl_getinfo($curl, CURLINFO_HEADER_SIZE); curl_setopt($curl, CURLOPT_COOKIELIST, 'FLUSH');
        return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $offset), 'body' => substr($raw, $offset)];
    };
    try {
        $draft = $api('record', query: ['id' => $form])['draft']; $field = 'ffed193a-0925-4543-ad59-45c14bfe5df6';
        $draft['elements'] = [['uuid' => $field, 'type' => 'field']];
        $draft['fields'] = [['uuid' => $field, 'name' => 'answer', 'type' => 'text', 'config' => ['required' => true]]];
        $draft['security']['captcha'] = ['mode' => 'none'];
        $saved = $api('save', ['id' => $form, 'revision' => 0, 'draft' => $draft]);
        $api('publish', ['id' => $form, 'revision' => $saved['revision']]);
        $inputs = []; $tokens = [];
        foreach ([0, 1] as $guest) {
            $page = $http('option=com_nicode_form_studio&view=form&tmpl=component&id=' . $form, $guest);
            $xpath = $dom($page['body']); $node = $xpath->query('//form[@data-nfs-form]')->item(0);
            $assert($page['status'] === 200 && $node instanceof DOMElement, 'Guest CSRF form unavailable.');
            $inputs[$guest] = [];
            foreach ($xpath->query('.//input[@type="hidden"]', $node) as $input) {
                $name = $input->getAttribute('name'); $value = $input->getAttribute('value'); $inputs[$guest][$name] = $value;
                if (preg_match('/^[a-f0-9]{32}$/D', $name) && $value === '1') { $tokens[$guest] = $name; }
            }
            $assert(isset($tokens[$guest]), 'Joomla token absent from public form.');
        }
        $assert($tokens[0] !== $tokens[1], 'Independent guest sessions share a CSRF token.');
        $checks = 0;
        foreach (['submit', 'options', 'rows'] as $task) {
            $route = 'option=com_nicode_form_studio&task=form.' . $task . '&format=json';
            $bare = $inputs[0]; unset($bare[$tokens[0]]); $bare['format'] = 'json'; $bare['nfs'] = [$field => 'Accepted guest answer'];
            $cases = [
                'missing' => [$bare, [], ''],
                'wrong_value' => [$bare + [$tokens[0] => '0'], [], ''],
                'array_value' => [$bare + [$tokens[0] => ['1']], [], ''],
                'query_only' => [$bare, [], '&' . $tokens[0] . '=1'],
                'foreign_body' => [$bare + [$tokens[1] => '1'], [], ''],
                'foreign_header' => [$bare, ['X-CSRF-Token: ' . $tokens[1]], ''],
                'invalid_header' => [$bare, ['X-CSRF-Token: invalid'], ''],
            ];
            foreach ($cases as $case => [$post, $headers, $query]) {
                $response = $http($route . $query, 0, $post, $headers);
                $result = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
                $assert($response['status'] === 403 && ($result['category'] ?? $result['error'] ?? '') === 'session_error' && str_contains(strtolower($response['headers']), 'no-store'), 'Public CSRF failed for ' . $task . '/' . $case);
                $checks++;
            }
            $response = $http($route . '&' . $tokens[0] . '=1', 0);
            $assert($response['status'] === 405, 'Public GET reached POST handler ' . $task); $checks++;
        }
        $query = $pdo->prepare('SELECT COUNT(*) FROM j6_nicode_form_studio_submissions WHERE form_id=?'); $query->execute([$form]);
        $assert((int) $query->fetchColumn() === 0, 'Rejected CSRF requests stored answers.');
        foreach ([0, 1] as $guest) {
            $post = $inputs[$guest]; $post['format'] = 'json'; $post['nfs'] = [$field => 'Accepted guest answer']; $headers = [];
            if ($guest === 0) { unset($post[$tokens[$guest]]); $headers = ['X-CSRF-Token: ' . $tokens[$guest]]; }
            $response = $http('option=com_nicode_form_studio&task=form.submit&format=json', $guest, $post, $headers);
            $result = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
            $assert($response['status'] === 200 && ($result['accepted'] ?? false), 'Valid ' . ($guest === 0 ? 'header' : 'body') . ' CSRF token rejected.');
        }
        $query->execute([$form]); $assert((int) $query->fetchColumn() === 2, 'CSRF positive controls did not store exactly two responses.');
        file_put_contents($root . '/build/public-csrf-results.json', json_encode(['passed' => true, 'timestamp' => gmdate(DATE_ATOM), 'rejections' => $checks, 'form_id' => $form, 'positive_controls' => ['header', 'body']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        echo "Native public CSRF: three endpoints, 24 token/session/method rejections, zero rejected persistence, independent-session body/header positive controls passed.\n";
    } finally {
        foreach ($jars as $path) { if (is_file($path)) { unlink($path); } }
        $current = $api('record', query: ['id' => $form]);
        if ($current['form']['state'] === 'published') { $api('deactivate', ['id' => $form, 'revision' => (int) $current['form']['draft_revision'], 'state' => 'unpublished']); }
    }
})();
