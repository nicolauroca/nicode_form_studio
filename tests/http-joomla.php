<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = json_decode(file_get_contents($root . '/build/joomla-runtime-results.json'), true, 512, JSON_THROW_ON_ERROR);
require $root . '/build/joomla-6.0.0/configuration.php'; $configuration = new JConfig();
if ($configuration->db !== 'formstudio_joomla' || $configuration->host !== '127.0.0.1:13367' || $configuration->live_site !== 'http://127.0.0.1:13371') { throw new RuntimeException('Refusing non-isolated HTTP test.'); }
$base = 'http://127.0.0.1:13371'; $jar = $root . '/build/http-cookie-' . bin2hex(random_bytes(6)) . '.txt';
function nativeHttp(string $url, string $cookieJar, ?array $post = null): array
{
    if (!str_starts_with($url, 'http://127.0.0.1:13371/')) { throw new RuntimeException('Unexpected HTTP destination.'); }
    $handle = curl_init($url);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_PROXY => '', CURLOPT_HEADER => true]);
    if ($post !== null) { curl_setopt($handle, CURLOPT_POST, true); curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $response = curl_exec($handle);
    if (!is_string($response)) { throw new RuntimeException('Native HTTP test connection failed.'); }
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE); $headers = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_setopt($handle, CURLOPT_COOKIELIST, 'FLUSH');
    return ['status' => $status, 'headers' => substr($response, 0, $headers), 'body' => substr($response, $headers)];
}
function nativeForm(array $page, int $index = 0): array
{
    if ($page['status'] !== 200 || !str_contains(strtolower($page['headers']), 'no-store')) { throw new RuntimeException('Form page is unavailable or cacheable.'); }
    $document = new DOMDocument(); $previous = libxml_use_internal_errors(true);
    try { $document->loadHTML($page['body']); } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    $xpath = new DOMXPath($document); $forms = $xpath->query('//form[@data-nfs-form]'); $form = $forms->item($index);
    if (!$form instanceof DOMElement) { throw new RuntimeException('Installed form missing.'); }
    $hidden = [];
    foreach ($xpath->query('.//input[@type="hidden"]', $form) as $input) { $hidden[$input->getAttribute('name')] = $input->getAttribute('value'); }
    $ids = [];
    foreach ($forms as $instance) { $ids[] = $instance->getAttribute('id'); foreach ($xpath->query('.//*[@id]', $instance) as $element) { $ids[] = $element->getAttribute('id'); } }
    if (count($ids) !== count(array_unique($ids))) { throw new RuntimeException('Component/module DOM identities collide.'); }
    return ['action' => $form->getAttribute('action'), 'hidden' => $hidden, 'instances' => $forms->length];
}
function jsonResult(array $response, int $status, string $category): array
{
    $result = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    if ($response['status'] !== $status || ($result['category'] ?? '') !== $category || !str_contains(strtolower($response['headers']), 'application/json') || !str_contains(strtolower($response['headers']), 'no-store')) { throw new RuntimeException('Unexpected HTTP status, category or response headers.'); }
    return $result;
}
try {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/joomla-menu.php'), $menuOutput, $menuExit);
    if ($menuExit !== 0) { throw new RuntimeException('Native menu fixture failed.'); }
    $menuFixture = json_decode(file_get_contents($root . '/build/native-menu-fixture.json'), true, 512, JSON_THROW_ON_ERROR);
    $menuForm = nativeForm(nativeHttp($base . '/index.php?Itemid=' . $menuFixture['menu_id'], $jar));
    if ((int) $menuForm['hidden']['form_id'] !== (int) $fixture['form_id'] || $menuForm['instances'] !== 3) { throw new RuntimeException('Native menu failed to resolve the shared component/module runtime.'); }
    $url = $base . '/index.php?option=com_nicode_form_studio&view=form&id=' . (int) $fixture['form_id'];
    $form = nativeForm(nativeHttp($url, $jar)); $post = $form['hidden']; $post['format'] = 'json';
    $csrf = array_values(array_diff(array_keys($form['hidden']), ['form_id', 'version_id', 'attempt', 'instance', 'channel']));
    if (count($csrf) !== 1) { throw new RuntimeException('Expected one native CSRF field.'); }
    $action = $base . $form['action']; $missing = $post; unset($missing[$csrf[0]]);
    jsonResult(nativeHttp($action, $jar, $missing), 403, 'session_error');
    $invalid = jsonResult(nativeHttp($action, $jar, $post), 422, 'validation_error');
    if (!isset($invalid['errors'][$fixture['field_uuid']])) { throw new RuntimeException('Required field error missing.'); }
    $post['nfs'] = [$fixture['field_uuid'] => 'HTTP synthetic answer', 'forged-field' => 'discard'];
    $accepted = jsonResult(nativeHttp($action, $jar, $post), 200, 'success');
    $replay = jsonResult(nativeHttp($action, $jar, $post), 200, 'success');
    if (!($replay['replayed'] ?? false) || $replay['reference'] !== $accepted['reference']) { throw new RuntimeException('HTTP retry duplicated a submission.'); }
    $changed = $post; $changed['nfs'][$fixture['field_uuid']] = 'Changed attempt';
    jsonResult(nativeHttp($action, $jar, $changed), 403, 'session_error');
    $pageForm = nativeForm(nativeHttp($url, $jar));
    $invalidPage = nativeHttp($base . $pageForm['action'], $jar, $pageForm['hidden']);
    if ($invalidPage['status'] !== 422 || !str_contains($invalidPage['body'], 'Your answer:') || !str_contains($invalidPage['body'], 'This field is required.')) { file_put_contents($root . '/build/http-page-failure.html', $invalidPage['body']); throw new RuntimeException('Full-page validation failed; HTTP ' . $invalidPage['status']); }
    $pagePost = $pageForm['hidden']; $pagePost['nfs'] = [$fixture['field_uuid'] => 'No JavaScript answer'];
    $pageResponse = nativeHttp($base . $pageForm['action'], $jar, $pagePost);
    if ($pageResponse['status'] !== 200 || !str_contains($pageResponse['body'], 'Your response has been received.') || !str_contains($pageResponse['body'], 'No JavaScript answer')) { throw new RuntimeException('Full-page success or value preservation failed.'); }
    $db = new PDO('mysql:host=127.0.0.1;port=13367;dbname=formstudio_joomla;charset=utf8mb4', $configuration->user, $configuration->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $statement = $db->prepare('SELECT canonical_payload FROM j6_nicode_form_studio_submissions WHERE form_id = ? AND uuid = ?'); $statement->execute([$fixture['form_id'], $accepted['reference']]); $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
    $expectedValues = [$fixture['field_uuid'] => 'HTTP synthetic answer', $fixture['secret_uuid'] => null]; ksort($expectedValues);
    if (count($rows) !== 1 || json_decode($rows[0], true, 512, JSON_THROW_ON_ERROR)['values'] !== $expectedValues) { throw new RuntimeException('HTTP canonical persistence or unknown-field rejection failed.'); }
    $moduleForm = nativeForm(nativeHttp($url, $jar), 1);
    if ($moduleForm['instances'] !== 3 || $moduleForm['hidden']['channel'] !== 'module') { throw new RuntimeException('Expected component plus two native modules. Run tools/prepare-http-modules.php.'); }
    $modulePost = $moduleForm['hidden']; $modulePost['format'] = 'json'; $modulePost['nfs'] = [$fixture['field_uuid'] => 'HTTP module answer'];
    $wrongChannel = $modulePost; $wrongChannel['channel'] = 'component';
    jsonResult(nativeHttp($base . $moduleForm['action'], $jar, $wrongChannel), 403, 'session_error');
    $moduleResult = jsonResult(nativeHttp($base . $moduleForm['action'], $jar, $modulePost), 200, 'success');
    $statement = $db->prepare('SELECT channel FROM j6_nicode_form_studio_submissions WHERE uuid = ?'); $statement->execute([$moduleResult['reference']]);
    if ($statement->fetchColumn() !== 'module') { throw new RuntimeException('Native module submission channel lost.'); }
    file_put_contents($root . '/build/joomla-http-results.json', json_encode(['passed' => true, 'timestamp' => gmdate(DATE_ATOM), 'checks' => ['native GET', 'no-store', 'native CSRF rejection', 'required-field validation', 'JSON submit', 'technical replay', 'fingerprint tampering rejection', 'full-page errors', 'full-page success and retained values', 'canonical persistence', 'three independent DOM instances', 'module channel token binding', 'native module persistence']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Installed Joomla HTTP: native CSRF, AJAX, full-page validation/success, replay, tamper rejection, canonical persistence and two independent modules passed.\n";
} finally { if (is_file($jar)) { unlink($jar); } }
