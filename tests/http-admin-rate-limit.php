<?php
declare(strict_types=1);

$rateForm = $api('create', ['name' => 'Rate window fixture', 'alias' => 'rate-' . bin2hex(random_bytes(6))]);
$rateDraft = $api('record', query: ['id' => $rateForm['id']])['draft'];
$rateField = '184d10d6-6e7d-4c33-844f-977ee530b23c';
$rateDraft['elements'] = [['uuid' => $rateField, 'type' => 'field']];
$rateDraft['fields'] = [['uuid' => $rateField, 'type' => 'text', 'name' => 'answer', 'config' => ['required' => true]]];
$rateDraft['security'] = ['captcha' => ['mode' => 'none'], 'rate_limit' => 1, 'rate_window' => 3600];
$rateRevision = $api('save', ['id' => $rateForm['id'], 'revision' => 0, 'draft' => $rateDraft])['revision'];
$api('publish', ['id' => $rateForm['id'], 'revision' => $rateRevision]);
$rateJars = [];
$rateHttp = static function (string $path, string $cookie, ?array $post = null): array {
    static $requestNumber = 0;
    if (!str_starts_with($path, '/index.php')) { throw new RuntimeException('Unexpected rate fixture route.'); }
    $curl = curl_init('http://127.0.0.1:13371' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie, CURLOPT_HTTPHEADER => ['X-Forwarded-For: 203.0.113.' . ++$requestNumber]]);
    if ($post !== null) { curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = curl_exec($curl); if (!is_string($raw)) { throw new RuntimeException('Rate fixture HTTP failed.'); }
    $offset = curl_getinfo($curl, CURLINFO_HEADER_SIZE); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_setopt($curl, CURLOPT_COOKIELIST, 'FLUSH');
    return ['status' => $status, 'headers' => substr($raw, 0, $offset), 'body' => substr($raw, $offset)];
};
try {
    foreach ([0, 1] as $session) {
        $rateCookie = $root . '/build/rate-cookie-' . bin2hex(random_bytes(6)) . '.txt'; $rateJars[] = $rateCookie;
        $ratePage = $rateHttp('/index.php?option=com_nicode_form_studio&view=form&id=' . $rateForm['id'], $rateCookie);
        $rateDom = $dom($ratePage['body']); $rateNode = $rateDom->query('//form[@data-nfs-form]')->item(0);
        $assert($ratePage['status'] === 200 && $rateNode instanceof DOMElement, 'Rate fixture form unavailable.');
        $ratePost = ['format' => 'json'];
        foreach ($rateDom->query('.//input[@type="hidden"]', $rateNode) as $input) { $ratePost[$input->getAttribute('name')] = $input->getAttribute('value'); }
        $rateReply = $rateHttp($rateNode->getAttribute('action'), $rateCookie, $ratePost);
        $rateResult = json_decode($rateReply['body'], true, 512, JSON_THROW_ON_ERROR);
        $assert($rateReply['status'] === ($session === 0 ? 422 : 429) && $rateResult['category'] === ($session === 0 ? 'validation_error' : 'rate_limited'), 'Rate limit was bypassed by replacing the Joomla session.');
        if ($session === 1) {
            $assert(preg_match('/Retry-After: ([1-9][0-9]*)/i', $rateReply['headers'], $retry) === 1 && (int) $retry[1] <= 3600, 'Missing bounded Retry-After.');
            $optionsReply = $rateHttp('/index.php?option=com_nicode_form_studio&task=form.options&format=json', $rateCookie, $ratePost);
            $assert($optionsReply['status'] === 200 && json_decode($optionsReply['body'], true)['ok'] === true, 'Submission limiter contaminated the separate option query scope.');
        }
    }
    file_put_contents($root . '/build/native-rate-fixture.json', json_encode(['form_id' => $rateForm['id'], 'field' => $rateField], JSON_THROW_ON_ERROR));
    echo "Native rate limiting: published policy, transport-address scope across new sessions, 429/Retry-After and separate options budget passed.\n";
} finally { foreach ($rateJars as $cookie) { if (is_file($cookie)) { unlink($cookie); } } }
