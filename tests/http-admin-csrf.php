<?php
declare(strict_types=1);

// Explicit inventory: new mutating controller actions must extend this matrix.
$tasks = [
    'form.create', 'form.duplicate', 'form.duplicateElement', 'form.save', 'form.publish',
    'form.deactivate', 'form.bulk', 'form.deletePermanently', 'form.restore', 'form.settings',
    'form.applyPermissions', 'form.previewOptions', 'form.previewRows',
    'submission.retry', 'submission.state', 'submission.note', 'submission.saveView',
    'submission.removeView', 'submission.download', 'submission.reveal',
    'job.enqueue', 'job.cancel', 'job.tick', 'job.download', 'purge.prepare',
    'optionset.create', 'optionset.save', 'optionset.source',
    'datasource.capture', 'datasource.configure', 'datasource.bind',
    'template.create', 'template.save', 'template.bind', 'template.capture', 'template.preview', 'template.apply',
    'definition.preview', 'definition.import',
];
$pdo = new PDO('mysql:host=127.0.0.1;port=13367;dbname=formstudio_joomla', $configuration->user, $configuration->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$snapshot = static function () use ($pdo): array {
    $result = [];
    foreach (['forms', 'form_versions', 'submissions', 'action_runs', 'jobs', 'audit_log'] as $table) {
        $result[$table] = $pdo->query('SELECT COUNT(*) AS total, MAX(id) AS last_id FROM j6_nicode_form_studio_' . $table)->fetch(PDO::FETCH_ASSOC);
    }
    return $result;
};
$before = $snapshot(); $checks = 0;
foreach ($tasks as $task) {
    $url = $base . '?' . http_build_query(['option' => 'com_nicode_form_studio', 'task' => $task, 'format' => 'json']);
    foreach (['missing' => [], 'wrong_value' => [$token => '0'], 'array_value' => [$token => ['1']], 'wrong_name' => [hash('sha256', $token) => '1'], 'query_only' => []] as $case => $fields) {
        // Empty payload deliberately cannot identify or authorize destructive work
        // even if a regression were to reach the action closure.
        $response = $request($url . ($case === 'query_only' ? '&' . urlencode($token) . '=1' : ''), ['payload' => '{}'] + $fields);
        $decoded = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        $assert($response['status'] === 403 && ($decoded['error'] ?? '') === 'session_error' && ($decoded['ok'] ?? null) === false && str_contains(strtolower($response['headers']), 'no-store'), 'CSRF boundary failed for ' . $task . '/' . $case);
        $checks++;
    }
    $response = $request($url . '&' . urlencode($token) . '=1');
    $decoded = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
    $assert($response['status'] === 405 && ($decoded['error'] ?? '') === 'method_not_allowed', 'GET with token reached protected POST action ' . $task);
    $checks++;
}
$assert($snapshot() === $before, 'Rejected administrative CSRF requests changed row counts or created audit/jobs.');
$created = $api('create', ['name' => 'CSRF positive control', 'alias' => 'csrf-positive-' . bin2hex(random_bytes(6))]);
$record = $api('record', query: ['id' => $created['id']]);
$assert($record['form']['name'] === 'CSRF positive control', 'Valid native session/token positive control failed.');
file_put_contents($root . '/build/admin-csrf-results.json', json_encode(['passed' => true, 'timestamp' => gmdate(DATE_ATOM), 'tasks' => $tasks, 'rejected_requests' => $checks, 'positive_form' => $created['id']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Native administrator CSRF: " . count($tasks) . " POST endpoints, $checks missing/malformed/query-only/method rejections, stable row counts and valid-token positive control passed.\n";
require __DIR__ . '/http-public-csrf.php';
