<?php
declare(strict_types=1);
$root = dirname(__DIR__); require $root . '/build/joomla-6.0.0/configuration.php'; $configuration = new JConfig();
if ($configuration->db !== 'formstudio_joomla' || $configuration->host !== '127.0.0.1:13367' || $configuration->live_site !== 'http://127.0.0.1:13371') { throw new RuntimeException('Refusing non-isolated dynamic HTTP test.'); }
$fixture = json_decode(file_get_contents($root . '/build/native-dynamic-options.json'), true, 512, JSON_THROW_ON_ERROR);
$jar = $root . '/build/dynamic-cookie-' . bin2hex(random_bytes(6)) . '.txt'; $base = 'http://127.0.0.1:13371/index.php';
$request = static function (string $url, ?array $post = null) use ($jar): array {
    if (!str_starts_with($url, 'http://127.0.0.1:13371/')) { throw new RuntimeException('Invalid HTTP destination.'); }
    $curl = curl_init($url); curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 20]);
    if ($post !== null) { curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = curl_exec($curl); if (!is_string($raw)) { throw new RuntimeException('Dynamic HTTP connection failed.'); }
    $offset = curl_getinfo($curl, CURLINFO_HEADER_SIZE); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_setopt($curl, CURLOPT_COOKIELIST, 'FLUSH');
    return ['status' => $status, 'headers' => substr($raw, 0, $offset), 'body' => substr($raw, $offset)];
};
$assert = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
try {
    $page = $request($base . '?option=com_nicode_form_studio&view=form&id=' . $fixture['form_id']);
    $assert($page['status'] === 200 && !str_contains($page['body'], 'not-public'), 'Dynamic source configuration leaked or page failed.');
    $dom = new DOMDocument(); $previous = libxml_use_internal_errors(true); $dom->loadHTML($page['body']); libxml_clear_errors(); libxml_use_internal_errors($previous); $xpath = new DOMXPath($dom);
    $form = $xpath->query('//form[@data-nfs-form]')->item(0); $post = [];
    foreach ($xpath->query('.//input[@type="hidden"]', $form) as $input) { $post[$input->getAttribute('name')] = $input->getAttribute('value'); }
    $tokens = array_diff(array_keys($post), ['form_id', 'version_id', 'attempt', 'instance', 'channel']); $assert(count($tokens) === 1, 'Native CSRF token missing.'); $csrf = reset($tokens);
    $endpoint = $base . '?option=com_nicode_form_studio&task=form.options&format=json';
    $check = static function (array $response, int $status) use ($assert): array {
        $assert($response['status'] === $status && str_contains(strtolower($response['headers']), 'no-store'), 'Unexpected options HTTP status or caching.');
        return json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    };
    $check($request($endpoint), 405);
    $missing = $post; unset($missing[$csrf]); $check($request($endpoint, $missing), 403);
    $wrongChannel = $post; $wrongChannel['channel'] = 'module'; $check($request($endpoint, $wrongChannel), 403);
    $old = $post; $old['version_id'] = 2147483647; $check($request($endpoint, $old), 404);
    $post['nfs'] = [$fixture['fields']['country'] => 'ES'];
    $options = $check($request($endpoint, $post), 200);
    foreach (['select', 'radio', 'checkbox-group'] as $type) { $assert(count($options['options'][$fixture['fields'][$type]]) === 2, 'Dynamic options missing.'); }
    $assert(!str_contains(json_encode($options), 'not-public'), 'Private provider metadata escaped.');
    $post['nfs'][$fixture['fields']['country']] = 'FR'; $empty = $check($request($endpoint, $post), 200);
    foreach ($empty['options'] as $values) { $assert($values === [], 'Dependent source ignored the changed parent.'); }
    $post['nfs'][$fixture['fields']['select']] = 'MD'; $post['format'] = 'json';
    $tampered = $request($base . '?option=com_nicode_form_studio&task=form.submit&format=json', $post);
    $result = json_decode($tampered['body'], true, 512, JSON_THROW_ON_ERROR);
    $assert($tampered['status'] === 422 && isset($result['errors'][$fixture['fields']['select']]), 'Submit accepted a stale dynamic choice.');
    $post['nfs'][$fixture['fields']['country']] = 'ES'; $post['nfs'][$fixture['fields']['radio']] = 'MD'; $post['nfs'][$fixture['fields']['checkbox-group']] = ['BC'];
    $accepted = $request($base . '?option=com_nicode_form_studio&task=form.submit&format=json', $post); $result = json_decode($accepted['body'], true, 512, JSON_THROW_ON_ERROR);
    $assert($accepted['status'] === 200 && $result['accepted'], 'Valid dynamic selections were rejected.');
    file_put_contents($root . '/build/http-dynamic-options-results.json', json_encode(['passed' => true, 'timestamp' => gmdate(DATE_ATOM), 'form_id' => $fixture['form_id'], 'checks' => ['native method and CSRF', 'session-channel binding', 'published version', 'safe option projection', 'dependency refresh', 'stale choice rejection', 'valid submission']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Native dynamic HTTP: options POST, CSRF, channel/version binding, safe dependencies and authoritative submit validation passed.\n";
} finally { if (is_file($jar)) { unlink($jar); } }
