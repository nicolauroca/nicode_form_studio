<?php
declare(strict_types=1);

$root = dirname(__DIR__); require $root . '/src/lib_nicode_form_studio/autoload.php';
$configuration = json_decode(ltrim(file_get_contents($root . '/build/upload-test.json'), "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);
$fixture = $root . '/build/upload-valid.txt'; file_put_contents($fixture, 'genuine multipart upload');
$oversized = $root . '/build/upload-large.txt'; file_put_contents($oversized, str_repeat('a', 1025));
$binary = $root . '/build/upload-binary.txt'; file_put_contents($binary, "\x89PNG\r\n\x1a\n" . str_repeat("\0", 256));
$field = 'nfs[d8329dba-78e2-49bf-88aa-7dd3e5fab043]';
$request = static function (array $body) use ($configuration): array {
    $curl = curl_init('http://127.0.0.1:13369/upload');
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['X-Test-Nonce: ' . $configuration['nonce']], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '']);
    $response = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($response === false) { throw new RuntimeException('Local upload test server unavailable.'); }
    return [$status, json_decode($response, true, 512, JSON_THROW_ON_ERROR)];
};
$storage = new Nicode\FormStudio\Storage\LocalStorage($configuration['storage'], __DIR__ . '/http');
$keys = [];
try {
    [$status, $body] = $request([$field => new CURLFile($fixture, 'application/x-fake-browser-mime', 'document.txt')]);
    if ($status !== 200 || !$body['accepted'] || $body['receipts'][0]['mime'] !== 'text/plain' || $body['receipts'][0]['size'] !== filesize($fixture)) { throw new RuntimeException('Genuine multipart upload or byte-derived MIME failed.'); }
    $keys = $body['keys'];
    $stream = $storage->open($keys[0]); $bytes = stream_get_contents($stream); fclose($stream);
    if ($bytes !== file_get_contents($fixture)) { throw new RuntimeException('Stored upload differs from HTTP bytes.'); }
    foreach ([[$field => new CURLFile($fixture, 'text/plain', 'evil.php')], [$field => new CURLFile($oversized, 'text/plain', 'large.txt')], [$field => new CURLFile($binary, 'text/plain', 'fake.txt')], ['nfs[d8329dba-78e2-49bf-88aa-7dd3e5fab043][tmp_name]' => $fixture]] as $bad) {
        [$status] = $request($bad); if ($status !== 422) { throw new RuntimeException('Unsafe HTTP upload accepted.'); }
    }
    file_put_contents($root . '/build/http-upload-results.json', json_encode(['passed' => true, 'timestamp' => gmdate(DATE_ATOM), 'scenarios' => ['genuine PHP provenance', 'byte MIME ignores browser MIME', 'private byte storage', 'extension rejection', 'size rejection', 'MIME forgery rejection', 'POST reference rejection']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Real multipart HTTP upload: provenance, MIME, storage and negative cases verified.\n";
} finally {
    foreach ($keys as $key) { $storage->delete($key); }
    foreach ([$fixture, $oversized, $binary] as $path) { if (is_file($path)) { unlink($path); } }
}
