<?php
declare(strict_types=1);
$root = dirname(__DIR__); require $root . '/build/joomla-6.0.0/configuration.php'; $config = new JConfig();
if ($config->db !== 'formstudio_joomla' || $config->host !== '127.0.0.1:13367' || $config->live_site !== 'http://127.0.0.1:13371') { throw new RuntimeException('Refusing non-isolated native file test.'); }
$multiple = in_array('--multiple', $argv, true);
$fixture = json_decode(file_get_contents($root . '/build/native-file' . ($multiple ? '-multiple' : '') . '-fixture.json'), true, 512, JSON_THROW_ON_ERROR);
$packedTransport = in_array('--packed', $argv, true);
$base = 'http://127.0.0.1:13371'; $jar = $root . '/build/native-file-cookie-' . bin2hex(random_bytes(6)) . '.txt';
$bytes = 'Synthetic private file bytes ' . bin2hex(random_bytes(12)); $file = $root . '/build/native-file-upload-' . bin2hex(random_bytes(6)) . '.txt'; file_put_contents($file, $bytes);
$secondFile = $file . '.second.txt'; $secondBytes = 'Distinct second attachment ' . bin2hex(random_bytes(12));
if ($multiple) { file_put_contents($secondFile, $secondBytes); }
$expectedFiles = ['synthetic-private.txt' => $bytes];
if ($multiple) { $expectedFiles['second-private.txt'] = $secondBytes; }
$http = static function (string $path, ?array $post = null, bool $multipart = false) use ($base, $jar): array {
    if (!str_starts_with($path, '/index.php') && !str_starts_with($path, '/administrator/index.php')) { throw new RuntimeException('Unexpected local route.'); }
    $curl = curl_init($base . $path); curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($post !== null) { curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post)); }
    $raw = curl_exec($curl); if (!is_string($raw)) { throw new RuntimeException('Local file HTTP failed.'); }
    $length = curl_getinfo($curl, CURLINFO_HEADER_SIZE); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_setopt($curl, CURLOPT_COOKIELIST, 'FLUSH');
    return ['status' => $status, 'headers' => substr($raw, 0, $length), 'body' => substr($raw, $length)];
};
$dom = static function (string $html): DOMXPath {
    $document = new DOMDocument(); $prior = libxml_use_internal_errors(true);
    try { $document->loadHTML($html); } finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
    return new DOMXPath($document);
};
$assert = static function (bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } };
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=13367;dbname=formstudio_joomla;charset=utf8mb4', $config->user, $config->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $counts = static function () use ($pdo, $fixture): array {
        $result = [];
        foreach (['submissions', 'upload_staging'] as $table) {
            $query = $pdo->prepare('SELECT COUNT(*) FROM j6_nicode_form_studio_' . $table . ' WHERE form_id = ?'); $query->execute([$fixture['form_id']]); $result[$table] = (int) $query->fetchColumn();
        }
        return $result;
    };
    $beforeRejected = $counts();
    $page = $http('/index.php?option=com_nicode_form_studio&view=form&id=' . $fixture['form_id']); $xpath = $dom($page['body']); $form = $xpath->query('//form[@data-nfs-form]')->item(0);
    $assert($page['status'] === 200 && $form instanceof DOMElement, 'Native file form unavailable.'); $post = [];
    foreach ($xpath->query('.//input[@type="hidden"]', $form) as $input) { $post[$input->getAttribute('name')] = $input->getAttribute('value'); }
    $post['format'] = 'json';
    $uploadKey = 'nfs[' . $fixture['field_uuid'] . ']' . ($multiple ? '[0]' : '');
    $post[$uploadKey] = new CURLFile($file, 'text/plain', 'synthetic-private.txt');
    if ($multiple) { $post['nfs[' . $fixture['field_uuid'] . '][1]'] = new CURLFile($secondFile, 'text/plain', 'second-private.txt'); }
    if ($packedTransport) {
        $post['nfs_values'] = '{}';
        $forged = $post; unset($forged[$uploadKey], $forged['nfs[' . $fixture['field_uuid'] . '][1]']);
        $forged['nfs_values'] = json_encode([$fixture['field_uuid'] => ['tmp_name' => '/forged/private.txt', 'name' => 'forged.txt', 'error' => 0, 'size' => 1]], JSON_THROW_ON_ERROR);
        $rejected = $http($form->getAttribute('action'), $forged, true); $rejectedResult = json_decode($rejected['body'], true, flags: JSON_THROW_ON_ERROR);
        $assert($rejected['status'] === 422 && isset($rejectedResult['errors'][$fixture['field_uuid']]), 'Packed metadata substituted for a real uploaded file.');
    }
    foreach (['json', 'html'] as $format) {
        $invalidUpload = $post; $invalidUpload['format'] = $format;
        $badKey = $multiple ? 'nfs[' . $fixture['field_uuid'] . '][1]' : $uploadKey;
        $invalidUpload[$badKey] = new CURLFile($file, 'text/plain', 'forbidden.php');
        $rejectedUpload = $http($form->getAttribute('action'), $invalidUpload, true);
        $expectedMessage = 'Choose an allowed file for Native private file fixture <script>upload-message</script>';
        $assert($rejectedUpload['status'] === 422 && str_contains(strtolower($rejectedUpload['headers']), 'no-store'), 'Upload rejection status/cache policy failed.');
        if ($format === 'json') {
            $errorResult = json_decode($rejectedUpload['body'], true, 512, JSON_THROW_ON_ERROR);
            $assert($errorResult['accepted'] === false && $errorResult['category'] === 'upload_error' && $errorResult['message'] === $expectedMessage && isset($errorResult['errors'][$fixture['field_uuid']]), 'Configured upload error message or field error missing.');
        } else {
            $assert(str_contains($rejectedUpload['body'], htmlspecialchars($expectedMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) && !str_contains($rejectedUpload['body'], '<script>upload-message</script>'), 'Upload error message was missing or executable in HTML.');
            $retryDom = $dom($rejectedUpload['body']); $retryAttempt = $retryDom->query('//form[@data-nfs-form]//input[@name="attempt"]')->item(0);
            $assert($retryAttempt instanceof DOMElement && $retryAttempt->getAttribute('value') === $post['attempt'], 'Upload rejection discarded the recoverable attempt.');
        }
        $assert($counts() === $beforeRejected, 'Rejected upload persisted a response or staging reservation.');
    }
    $uploaded = $http($form->getAttribute('action'), $post, true); $result = json_decode($uploaded['body'], true, 512, JSON_THROW_ON_ERROR);
    $assert($uploaded['status'] === 200 && ($result['accepted'] ?? false), 'Native multipart upload rejected.');
    $replayed = $http($form->getAttribute('action'), $post, true); $replayResult = json_decode($replayed['body'], true, 512, JSON_THROW_ON_ERROR);
    $assert($replayed['status'] === 200 && ($replayResult['accepted'] ?? false) && $replayResult['reference'] === $result['reference'], 'Multipart technical replay created a different response.');
    $assert($counts() === ['submissions' => $beforeRejected['submissions'] + 1, 'upload_staging' => $beforeRejected['upload_staging']], 'Corrected upload/replay did not create exactly one response without staging leftovers.');
    $query = $pdo->prepare('SELECT s.id, f.uuid, f.storage_key, f.original_name FROM j6_nicode_form_studio_submissions s JOIN j6_nicode_form_studio_submission_files f ON f.submission_id = s.id WHERE s.uuid = ? AND s.form_id = ? ORDER BY f.id'); $query->execute([$result['reference'], $fixture['form_id']]); $storedFiles = $query->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($storedFiles) === count($expectedFiles) && array_column($storedFiles, 'original_name') === array_keys($expectedFiles), 'Uploaded file ownership or order was not persisted.');
    $stored = $storedFiles[0];
    foreach ($storedFiles as $ownedFile) {
        $query = $pdo->prepare('SELECT COUNT(*) FROM j6_nicode_form_studio_upload_staging WHERE provider = ? AND storage_key = ?');
        $query->execute(['local', $ownedFile['storage_key']]);
        $assert((int) $query->fetchColumn() === 0, 'Committed file retained a cleanup reservation.');
    }
    $downloadRoute = '/administrator/index.php?option=com_nicode_form_studio&task=submission.download&format=raw';
    $anonymous = $http($downloadRoute, ['form_id' => $fixture['form_id'], 'file' => $stored['uuid']]); $assert($anonymous['body'] !== $bytes && !str_contains(strtolower($anonymous['headers']), 'content-disposition: attachment'), 'Anonymous file download succeeded.');
    $login = $http('/administrator/index.php'); $xpath = $dom($login['body']); $loginPost = [];
    foreach ($xpath->query('//form[.//input[@name="username"]]//input[@type="hidden"]') as $input) { $loginPost[$input->getAttribute('name')] = $input->getAttribute('value'); }
    $credentials = json_decode(file_get_contents($root . '/build/joomla-test.json'), true, 512, JSON_THROW_ON_ERROR); $loginPost['username'] = $credentials['username']; $loginPost['passwd'] = $credentials['password']; unset($credentials);
    $loggedIn = $http('/administrator/index.php', $loginPost); unset($loginPost); $assert(in_array($loggedIn['status'], [302, 303], true), 'Administrator login failed.');
    $detail = $http('/administrator/index.php?option=com_nicode_form_studio&view=submission&form_id=' . $fixture['form_id'] . '&id=' . $stored['id']);
    $xpath = $dom($detail['body']); $section = $xpath->query('//*[@data-nfs-submission]')->item(0); $assert($detail['status'] === 200 && $section instanceof DOMElement, 'Uploaded response detail unavailable.');
    $assert(!str_contains($detail['body'], 'synthetic-private.txt') && !str_contains($detail['body'], $stored['storage_key']), 'Masked file metadata leaked.'); $token = $section->getAttribute('data-csrf');
    $reveal = $http('/administrator/index.php?option=com_nicode_form_studio&task=submission.reveal&format=json', [$token => '1', 'payload' => json_encode(['form_id' => $fixture['form_id'], 'id' => (int) $stored['id']])]);
    $revealed = json_decode($reveal['body'], true, 512, JSON_THROW_ON_ERROR); $assert($reveal['status'] === 200 && count($revealed['data']['files']) === count($expectedFiles), 'Explicit file metadata reveal failed.');
    $assert(array_column($revealed['data']['files'], 'uuid') === array_column($storedFiles, 'uuid'), 'Revealed file identities or order changed.');
    foreach ($storedFiles as $ownedFile) {
        $assert(!str_contains($detail['body'], $ownedFile['original_name']) && !str_contains($detail['body'], $ownedFile['storage_key']) && !str_contains($reveal['body'], $ownedFile['storage_key']), 'Sensitive file metadata or private storage key leaked.');
    }
    $downloadPost = ['form_id' => $fixture['form_id'], 'file' => $stored['uuid']];
    $assert($http($downloadRoute)['status'] === 405, 'File route accepts GET.');
    $assert($http($downloadRoute, $downloadPost)['status'] === 403, 'File route accepts missing CSRF.');
    $downloadPost[$token] = '1';
    $foreign = $downloadPost; $foreign['form_id'] = json_decode(file_get_contents($root . '/build/joomla-runtime-results.json'), true)['form_id'];
    $assert($http($downloadRoute, $foreign)['status'] === 404, 'Cross-form file download succeeded.');
    // Temporarily withdraw only this test response's storage pointer, restoring
    // it even if an assertion fails. Never move or delete the physical object.
    foreach (['provider' => 'fixture.missing-storage', 'storage_key' => bin2hex(random_bytes(32))] as $column => $missingValue) {
        $originalValue = $column === 'provider' ? 'local' : $stored['storage_key'];
        $update = $pdo->prepare('UPDATE j6_nicode_form_studio_submission_files SET ' . $column . ' = ? WHERE uuid = ? AND submission_id = ?');
        try {
            $update->execute([$missingValue, $stored['uuid'], $stored['id']]);
            $unavailable = $http($downloadRoute, $downloadPost);
            $assert($unavailable['status'] === 404 && !str_contains(strtolower($unavailable['headers']), 'content-disposition: attachment') && !str_contains($unavailable['body'], $missingValue) && !str_contains($unavailable['body'], $stored['storage_key']), 'Missing storage object/provider exposed internals or returned the wrong status.');
        } finally { $update->execute([$originalValue, $stored['uuid'], $stored['id']]); }
    }
    foreach ($storedFiles as $ownedFile) {
        $downloadPost['file'] = $ownedFile['uuid'];
        $download = $http($downloadRoute, $downloadPost);
        $assert($download['status'] === 200 && $download['body'] === $expectedFiles[$ownedFile['original_name']] && str_contains($download['headers'], rawurlencode($ownedFile['original_name'])) && str_contains(strtolower($download['headers']), 'application/octet-stream') && str_contains(strtolower($download['headers']), 'content-disposition: attachment') && str_contains(strtolower($download['headers']), 'no-store') && str_contains(strtolower($download['headers']), 'nosniff'), 'Private attachment stream or headers failed.');
    }
    $query = $pdo->prepare("SELECT COUNT(*) FROM j6_nicode_form_studio_audit_log WHERE submission_uuid = ? AND event_type = 'submission.file_download'"); $query->execute([$result['reference']]); $assert((int) $query->fetchColumn() === count($expectedFiles), 'File download audit missing.');
    file_put_contents($root . '/build/native-file-http' . ($multiple ? '-multiple' : '') . ($packedTransport ? '-packed' : '') . '-results.json', json_encode(['passed' => true, 'timestamp' => gmdate(DATE_ATOM), 'file_count' => count($expectedFiles), 'transport' => $packedTransport ? 'packed' : 'legacy', 'form_id' => $fixture['form_id'], 'checks' => ['native multipart persistence', 'anonymous denial', 'default sensitive masking', 'explicit reveal', 'CSRF/method/ownership', 'exact private attachment bytes', 'safe headers', 'download audit']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Installed native file HTTP: multipart upload, sensitive masking/reveal, scoped private bytes, headers and audited download passed.\n";
} finally { foreach ([$jar, $file, $secondFile] as $temporary) { if (is_file($temporary)) { unlink($temporary); } } }
