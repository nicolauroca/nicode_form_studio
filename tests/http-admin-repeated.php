<?php
declare(strict_types=1);
$visitorJar = $root . '/build/validator-cookie-' . bin2hex(random_bytes(6)) . '.txt';
$visitorRequest = static function (string $url, ?array $post = null, array $headers = []) use ($visitorJar): array {
    if (!str_starts_with($url, 'http://127.0.0.1:13371/index.php')) { throw new RuntimeException('Unexpected validator test destination.'); }
    $handle = curl_init($url);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_COOKIEJAR => $visitorJar, CURLOPT_COOKIEFILE => $visitorJar, CURLOPT_PROXY => '']);
    if ($headers !== []) { curl_setopt($handle, CURLOPT_HTTPHEADER, $headers); }
    if ($post !== null) { curl_setopt($handle, CURLOPT_POST, true); curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = curl_exec($handle); if (!is_string($body)) { throw new RuntimeException('Validator HTTP connection failed.'); }
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE); curl_setopt($handle, CURLOPT_COOKIELIST, 'FLUSH');
    return ['status' => $status, 'body' => $body];
};
$prefillDb = new PDO('mysql:host=127.0.0.1;port=13367;dbname=formstudio_joomla;charset=utf8mb4', $configuration->user, $configuration->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$submissionApi = static fn(string $task, array $query = [], ?array $payload = null, int $expected = 200, bool $csrf = true): array => $api('submission.'.$task, $payload, $query, $expected, $csrf);
try {
    require __DIR__.'/http-admin-layout.php';
    require __DIR__.'/http-admin-repeated-public.php';
    require __DIR__.'/http-admin-repeated-nested.php';
}
finally { if (is_file($visitorJar)) { unlink($visitorJar); } }
