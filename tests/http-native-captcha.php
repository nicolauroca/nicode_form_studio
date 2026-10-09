<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require $root . '/build/joomla-6.0.0/configuration.php'; $config = new JConfig();
if ($config->db !== 'formstudio_joomla' || $config->host !== '127.0.0.1:13367') { throw new RuntimeException('Refusing non-isolated CAPTCHA test.'); }
$fixture = json_decode(file_get_contents($root . '/build/native-captcha-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
$auth = json_decode(file_get_contents($root . '/build/upload-test.json'), true, flags: JSON_THROW_ON_ERROR);
$jar = $root . '/build/captcha-cookie-' . bin2hex(random_bytes(6)) . '.txt';
$http = static function (string $path, ?array $post = null) use ($jar, $auth): array {
    if (!str_starts_with($path, '/index.php')) { throw new RuntimeException('Unexpected CAPTCHA fixture route.'); }
    $curl = curl_init('http://127.0.0.1:13406' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => ['X-Test-Nonce: ' . $auth['nonce']]]);
    if ($post !== null) { curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = curl_exec($curl); if (!is_string($raw)) { throw new RuntimeException('CAPTCHA test server unavailable.'); }
    $offset = curl_getinfo($curl, CURLINFO_HEADER_SIZE); curl_setopt($curl, CURLOPT_COOKIELIST, 'FLUSH');
    return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $offset), 'body' => substr($raw, $offset)];
};
$dom = static function (string $html): DOMXPath { $document = new DOMDocument(); $before = libxml_use_internal_errors(true); $document->loadHTML($html); libxml_clear_errors(); libxml_use_internal_errors($before); return new DOMXPath($document); };
$assert = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$pdo = new PDO('mysql:host=127.0.0.1;port=13367;dbname=formstudio_joomla', $config->user, $config->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$count = static function () use ($pdo, $fixture): int { $query = $pdo->prepare('SELECT COUNT(*) FROM j6_nicode_form_studio_submissions WHERE form_id=?'); $query->execute([$fixture['form_id']]); return (int) $query->fetchColumn(); };
try {
    $before = $count();
    $page = $http('/index.php?option=com_nicode_form_studio&view=form&tmpl=component&id=' . $fixture['form_id']);
    $xpath = $dom($page['body']); $form = $xpath->query('//form[@data-nfs-form]')->item(0);
    $assert($page['status'] === 200 && $form instanceof DOMElement, 'Native CAPTCHA form unavailable: ' . $page['status']);
    $post = []; foreach ($xpath->query('.//input[@type="hidden"]', $form) as $input) { $post[$input->getAttribute('name')] = $input->getAttribute('value'); }
    $post['nfs'] = [$fixture['field'] => 'Valid field answer'];
    foreach (['wrong' => 'captcha_error', 'outage' => 'captcha_unavailable'] as $answer => $category) {
        foreach (['json', 'html'] as $format) {
            $post['format'] = $format; $post['formstudio_captcha'] = $answer;
            $response = $http($form->getAttribute('action'), $post);
            $expected = 'Configured ' . $category . ' Native CAPTCHA messages <script>captcha-marker</script>';
            $assert($response['status'] === 422 && str_contains(strtolower($response['headers']), 'no-store') && !str_contains($response['body'], 'Private CAPTCHA provider credential'), 'CAPTCHA rejection status/privacy failed: ' . $category . '/' . $format . '/' . $response['status']);
            if ($format === 'json') { $result = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR); $assert($result['accepted'] === false && $result['category'] === $category && $result['message'] === $expected, 'Native CAPTCHA message selection failed.'); }
            else { $assert(str_contains($response['body'], htmlspecialchars($expected, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) && !str_contains($response['body'], '<script>captcha-marker</script>'), 'Native CAPTCHA HTML message unsafe or absent.'); }
            $assert($count() === $before, 'Rejected CAPTCHA persisted a response.');
        }
    }
    $post['format'] = 'json'; $post['formstudio_captcha'] = 'fixture-valid';
    $accepted = $http($form->getAttribute('action'), $post); $result = json_decode($accepted['body'], true, flags: JSON_THROW_ON_ERROR);
    $assert($accepted['status'] === 200 && $result['accepted'] && $count() === $before + 1, 'Same-attempt CAPTCHA recovery failed.');
    echo "Native CAPTCHA messages: Joomla provider rejection/outage, JSON/HTML safe custom messages, no rejected persistence and same-attempt correction passed.\n";
} finally {
    if (is_file($jar)) { unlink($jar); }
    $query = $pdo->prepare("UPDATE j6_nicode_form_studio_forms SET state='unpublished' WHERE id=?"); $query->execute([$fixture['form_id']]);
}
