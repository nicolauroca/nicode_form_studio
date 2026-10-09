<?php
declare(strict_types=1);

require_once $root . '/src/lib_nicode_form_studio/autoload.php';
$cookie = $root . '/build/success-cookie-' . bin2hex(random_bytes(6)) . '.txt'; $id = null;
$http = static function (string $url, ?array $post = null) use ($cookie): array {
    if (!str_starts_with($url, 'http://127.0.0.1:13371/')) { throw new RuntimeException('Unexpected success fixture destination.'); }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie, CURLOPT_HEADER => true]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = curl_exec($ch); if (!is_string($raw)) { throw new RuntimeException('Success fixture connection failed.'); }
    $offset = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_setopt($ch, CURLOPT_COOKIELIST, 'FLUSH');
    return ['status' => $status, 'headers' => substr($raw, 0, $offset), 'body' => substr($raw, $offset)];
};
try {
    $id = $api('create', ['name' => 'Native success acceptance', 'alias' => 'native-success-' . bin2hex(random_bytes(6))])['id'];
    $draft = $api('record', query: ['id' => $id])['draft']; $draft['elements'] = []; $draft['fields'] = [];
    foreach (['authorized', 'private', 'unselected'] as $name) {
        $uuid = Nicode\FormStudio\Domain\Uuid::create(); $ids[$name] = $uuid;
        $draft['elements'][] = ['uuid' => $uuid, 'type' => 'field'];
        $draft['fields'][] = ['uuid' => $uuid, 'type' => 'text', 'name' => $name, 'sensitive' => $name === 'private', 'config' => ['label' => '<b>' . $name . '</b>']];
    }
    $draft['security']['captcha'] = ['mode' => 'none'];
    $draft['post_submit'] = ['behavior' => 'hide', 'messages' => ['success_heading' => '<script>{{form.name}}</script>', 'success' => 'Received'], 'summary_fields' => [$ids['authorized']]];
    $saved = $api('save', ['id' => $id, 'revision' => 0, 'draft' => $draft]); $api('publish', ['id' => $id, 'revision' => $saved['revision']]);
    $checks = [];
    foreach (['json', 'html'] as $format) {
        $page = $http('http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id=' . $id); $xp = $dom($page['body']); $form = $xp->query('//form[@data-nfs-form]')->item(0);
        $assert($page['status'] === 200 && $form instanceof DOMElement, 'Success form did not render.'); $post = [];
        foreach ($xp->query('.//input[@type="hidden"]', $form) as $input) { $post[$input->getAttribute('name')] = $input->getAttribute('value'); }
        $post['nfs'] = [$ids['authorized'] => '<img src=x onerror=alert(1)>', $ids['private'] => 'SECRET-MUST-NOT-APPEAR', $ids['unselected'] => 'UNSELECTED-MUST-NOT-APPEAR'];
        if ($format === 'json') { $post['format'] = 'json'; }
        $response = $http('http://127.0.0.1:13371' . $form->getAttribute('action'), $post);
        $assert($response['status'] === 200, 'Success request failed: ' . $format . '/' . $response['status']);
        $assert(!str_contains($response['body'], 'SECRET-MUST-NOT-APPEAR') && !str_contains($response['body'], 'UNSELECTED-MUST-NOT-APPEAR'), 'Unauthorized summary disclosure.');
        if ($format === 'json') {
            $result = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
            $assert(($result['accepted'] ?? false) && ($result['processed'] ?? false), 'JSON processing not complete.');
            $assert($result['heading'] === '<script>Native success acceptance</script>', 'Heading tokens not rendered.');
            $assert($result['summary'] === [['label' => '<b>authorized</b>', 'value' => '<img src=x onerror=alert(1)>']], 'JSON summary not explicit.');
        } else {
            $xp = $dom($response['body']); $result = $xp->query('//div[contains(@class,"nfs-confirmation")]')->item(0);
            $assert($result instanceof DOMElement, 'Traditional confirmation missing.');
            $assert($xp->evaluate('string(.//h2)', $result) === '<script>Native success acceptance</script>', 'HTML heading missing or unsafe.');
            $assert($xp->evaluate('string(.//dt)', $result) === '<b>authorized</b>' && $xp->evaluate('string(.//dd)', $result) === '<img src=x onerror=alert(1)>', 'HTML summary missing or unsafe.');
            $assert($xp->query('.//script|.//img|.//dt/b', $result)->length === 0, 'Confirmation interpreted HTML.');
        }
        $checks[] = $format;
    }
    file_put_contents($root . '/build/native-success-results.json', json_encode(['passed' => true, 'timestamp' => gmdate(DATE_ATOM), 'form_id' => $id, 'checks' => $checks, 'safe_heading_and_authorized_summary' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Native success: configured heading and explicit summary, safe HTML/text, private and unselected exclusion passed in JSON and HTML.\n";
} finally {
    if (is_file($cookie)) { unlink($cookie); }
    if ($id !== null) { $current = $api('record', query: ['id' => $id]); if ($current['form']['state'] === 'published') { $api('deactivate', ['id' => $id, 'revision' => (int) $current['form']['draft_revision'], 'state' => 'unpublished']); } }
}
