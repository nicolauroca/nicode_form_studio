<?php
declare(strict_types=1);

// Real PHP multipart parser, native Joomla and persisted canonical responses.
// Prepare the 1,500-field fixture with tests/http-admin.php before running this.
$root = dirname(__DIR__); $site = $root . '/build/joomla-6.0.0';
require $site . '/configuration.php'; $configuration = new JConfig();
if ($configuration->db !== 'formstudio_joomla' || $configuration->host !== '127.0.0.1:13367' || $configuration->dbprefix !== 'j6_') { throw new RuntimeException('Refusing non-isolated request-limit fixture.'); }
$fixture = json_decode(file_get_contents($root . '/build/native-large-form-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
if (($fixture['fields'] ?? null) !== 1500 || !is_int($fixture['form_id'] ?? null)) { throw new RuntimeException('Prepare the native large-form fixture first.'); }
$db = new PDO('mysql:host=127.0.0.1;port=13367;dbname=formstudio_joomla;charset=utf8mb4', $configuration->user, $configuration->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$count = $db->prepare('SELECT COUNT(*) FROM j6_nicode_form_studio_submissions WHERE form_id = ?');
$latest = $db->prepare('SELECT canonical_payload FROM j6_nicode_form_studio_submissions WHERE form_id = ? ORDER BY id DESC LIMIT 1');
$results = [];
$cases = [
    'legacy-variable-cap' => ['vars' => 1000, 'parts' => -1, 'bytes' => '8M', 'packed' => false, 'accepted' => false],
    'legacy-multipart-cap' => ['vars' => 2000, 'parts' => 1000, 'bytes' => '8M', 'packed' => false, 'accepted' => false],
    'legacy-sufficient-budget' => ['vars' => 2000, 'parts' => -1, 'bytes' => '8M', 'packed' => false, 'accepted' => true],
    'packed-post-byte-cap' => ['vars' => 1000, 'parts' => -1, 'bytes' => '16K', 'packed' => true, 'accepted' => false],
    'packed-post-byte-cap-json' => ['vars' => 1000, 'parts' => -1, 'bytes' => '16K', 'packed' => true, 'accepted' => false, 'json' => true],
];
foreach ($cases as $name => $case) {
    $port = 13375;
    $occupied = @fsockopen('127.0.0.1', $port, $socketError, $socketMessage, 0.1);
    if (is_resource($occupied)) { fclose($occupied); throw new RuntimeException('Request-limit fixture port is already in use.'); }
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'max_input_vars=' . $case['vars'], '-d', 'max_multipart_body_parts=' . $case['parts'], '-d', 'post_max_size=' . $case['bytes'], '-S', '127.0.0.1:' . $port, '-t', $site], [0 => ['pipe', 'r'], 1 => ['file', $root . '/build/request-limits-' . $name . '.log', 'w'], 2 => ['file', $root . '/build/request-limits-' . $name . '-errors.log', 'w']], $pipes, $site);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start request-limit fixture.'); }
    fclose($pipes[0]);
    $jar = $root . '/build/request-limits-' . bin2hex(random_bytes(8)) . '.cookies';
    try {
        $ready = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if (!proc_get_status($process)['running']) { throw new RuntimeException('Request-limit fixture stopped during startup.'); }
            $socket = @fsockopen('127.0.0.1', $port, $socketError, $socketMessage, 0.1);
            if (is_resource($socket)) { fclose($socket); $ready = true; break; }
            usleep(50000);
        }
        if (!$ready) { throw new RuntimeException('Request-limit fixture did not become ready.'); }
        $request = static function (string $query, ?array $post = null, bool $json = false) use ($port, $jar): array {
            $handle = curl_init('http://127.0.0.1:' . $port . '/index.php?' . $query);
            curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_PROXY => '']);
            // A flat array makes cURL emit the actual multipart form body in DOM order.
            if ($post !== null) { curl_setopt($handle, CURLOPT_POSTFIELDS, $post); }
            if ($json) { curl_setopt($handle, CURLOPT_HTTPHEADER, ['Accept: application/json']); }
            $body = curl_exec($handle);
            if (!is_string($body)) { throw new RuntimeException('Request-limit HTTP failure.'); }
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_setopt($handle, CURLOPT_COOKIELIST, 'FLUSH');
            return ['status' => $status, 'body' => $body];
        };
        $page = $request('option=com_nicode_form_studio&view=form&id=' . $fixture['form_id']);
        $document = new DOMDocument(); $previous = libxml_use_internal_errors(true);
        try { $document->loadHTML($page['body']); } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $xpath = new DOMXPath($document); $form = $xpath->query('//form[@data-nfs-form]')->item(0);
        if ($page['status'] !== 200 || !$form instanceof DOMElement) { throw new RuntimeException('Native large form did not render under the selected budget.'); }
        $post = []; $values = []; $seenEnvelope = false;
        foreach ($xpath->query('.//input[@name and not(@disabled)]', $form) as $input) {
            $fieldName = $input->getAttribute('name'); $value = $input->getAttribute('value');
            if (preg_match('/^nfs\[([0-9a-f-]{36})\]$/D', $fieldName, $match)) {
                if ($seenEnvelope) { throw new RuntimeException('Security envelope no longer follows all answer fields.'); }
                $values[$match[1]] = $value;
            } elseif ($fieldName === 'form_id') { $seenEnvelope = true; }
            $post[$fieldName] = $value;
        }
        if (count($values) !== 1500 || !$seenEnvelope) { throw new RuntimeException('Large-form controls or trailing envelope missing.'); }
        if ($case['packed']) {
            $post = array_filter($post, static fn (string $key): bool => !str_starts_with($key, 'nfs['), ARRAY_FILTER_USE_KEY);
            $post['nfs_values'] = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        $count->execute([$fixture['form_id']]); $before = (int) $count->fetchColumn();
        if ($case['json'] ?? false) { $post['format'] = 'json'; }
        $response = $request('option=com_nicode_form_studio&task=form.submit', $post, $case['json'] ?? false);
        $count->execute([$fixture['form_id']]); $after = (int) $count->fetchColumn();
        if ($case['accepted']) {
            if ($response['status'] !== 200 || !str_contains($response['body'], 'Your response has been received.') || $after !== $before + 1) { throw new RuntimeException('Sufficient legacy request budget did not accept exactly one response.'); }
            $latest->execute([$fixture['form_id']]); $payload = json_decode($latest->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
            if ($payload['values'] !== $values) { throw new RuntimeException('Legacy multipart lost or changed canonical values.'); }
        } elseif ($case['json'] ?? false) {
            $result = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
            if ($response['status'] !== 413 || ($result['category'] ?? '') !== 'request_too_large' || ($result['accepted'] ?? true) !== false || !str_contains($result['message'] ?? '', 'was not saved') || $after !== $before) { throw new RuntimeException('Oversized JSON response lost its actionable category or persisted data.'); }
        } elseif ($response['status'] !== ($case['bytes'] === '16K' ? 413 : 403) || !str_contains($response['body'], $case['bytes'] === '16K' ? 'Your response exceeds the server&#039;s size limit' : 'Your session or form has expired.') || $after !== $before) {
            throw new RuntimeException('Truncated request was not rejected without persistence: ' . $name);
        }
        $results[$name] = $case + ['http_status' => $response['status'], 'persisted' => $after - $before, 'fields' => count($values)];
        echo 'PASS ' . $name . ': HTTP ' . $response['status'] . ', persisted ' . ($after - $before) . "\n";
    } finally {
        proc_terminate($process); proc_close($process);
        if (is_file($jar)) { unlink($jar); }
    }
}
file_put_contents($root . '/build/native-request-limits-results.json', json_encode(['timestamp' => gmdate(DATE_ATOM), 'form_id' => $fixture['form_id'], 'cases' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
