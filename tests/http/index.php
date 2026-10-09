<?php
declare(strict_types=1);

// Test-only fixture, excluded from every extension package.
$root = dirname(__DIR__, 2);
require $root . '/src/lib_nicode_form_studio/autoload.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/fixture-provider.js') { header('Content-Type: text/javascript'); header('Cache-Control: no-store'); readfile(__DIR__ . '/../fixtures/plg_formstudio_providerfixture/media/js/fixture.js'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/fixture-provider.css') { header('Content-Type: text/css'); readfile(__DIR__ . '/../fixtures/plg_formstudio_providerfixture/media/css/fixture.css'); exit; }
$path = str_replace('/media/com_nicode_form_studio/', '/assets/', $path);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/form') { require __DIR__ . '/form.php'; exit; }
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('~^/assets/(js|css)/([a-z-]+\.(js|css))$~D', $path, $asset)) {
    $file = $root . '/src/com_nicode_form_studio/media/' . $asset[1] . '/' . $asset[2];
    if (!is_file($file)) { http_response_code(404); exit; }
    header('Content-Type: ' . ($asset[3] === 'js' ? 'text/javascript' : 'text/css')); header('Cache-Control: no-store'); readfile($file); exit;
}
header('Content-Type: application/json');
$configuration = json_decode(ltrim(file_get_contents($root . '/build/upload-test.json'), "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);
if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || !hash_equals($configuration['nonce'], $_SERVER['HTTP_X_TEST_NONCE'] ?? '')) { http_response_code(403); echo '{}'; exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/mail-attachments') { require __DIR__ . '/mail-attachments.php'; exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/repeated') { require __DIR__ . '/repeated.php'; exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/upload') { http_response_code(405); echo '{}'; exit; }
try {
    $storage = new Nicode\FormStudio\Storage\LocalStorage($configuration['storage'], __DIR__);
    $gateway = new Nicode\FormStudio\Storage\HttpUploadGateway(new Nicode\FormStudio\Storage\UploadInspector(), $storage);
    $field = 'd8329dba-78e2-49bf-88aa-7dd3e5fab043';
    $files = Nicode\FormStudio\Storage\FileInput::extract($_FILES['nfs'] ?? [], [$field]);
    if (empty($files[$field])) { throw new InvalidArgumentException('Missing upload.'); }
    $received = $gateway->receive($files[$field], new Nicode\FormStudio\Storage\UploadPolicy(['txt'], ['text/plain'], 1024, 2));
    echo json_encode(['accepted' => true, 'receipts' => array_column($received, 'receipt'), 'keys' => array_map(static fn ($entry): string => $entry['file']->key, $received)], JSON_THROW_ON_ERROR);
} catch (Throwable) { http_response_code(422); echo '{"accepted":false}'; }
