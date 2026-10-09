<?php
declare(strict_types=1);

$fixture = json_decode(file_get_contents($root . '/build/native-job-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
$formId = (int) $fixture['form_id']; $responseId = (int) $fixture['response_ids'][0];
$pdo = new PDO('mysql:host=127.0.0.1;port=13367;dbname=formstudio_joomla;charset=utf8mb4', $configuration->user, $configuration->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$assert($configuration->dbprefix === 'j6_', 'Unexpected native prefix.');
$created = [];
try {
    foreach ([['form_id' => $formId], ['form_id' => $formId, 'submission_id' => $responseId], ['form_id' => $formId, 'received_from' => '2026-01-01 00:00:00', 'received_to' => '2026-12-31 23:59:59'], ['form_id' => 0, 'all_forms' => true]] as $selection) {
        $payload = ['type' => 'reindex'] + $selection;
        $api('job.enqueue', $payload, expected: 403, csrf: false);
        $id = $api('job.enqueue', $payload)['id']; $created[] = $id;
        $query = $pdo->prepare('SELECT parameters, state FROM j6_nicode_form_studio_jobs WHERE id = ?'); $query->execute([$id]); $row = $query->fetch(PDO::FETCH_ASSOC);
        $expected = isset($selection['all_forms']) ? ['all_forms' => true] : $selection;
        $actual = json_decode($row['parameters'], true, flags: JSON_THROW_ON_ERROR); ksort($expected); ksort($actual);
        $assert($row['state'] === 'pending' && $actual === $expected, 'Native enqueue lost reindex scope.');
    }
    $api('job.enqueue', ['type' => 'reindex', 'form_id' => $formId, 'received_from' => '2026-02-30 00:00:00'], expected: 422);
    foreach ([$formId, 0] as $selection) {
        $page = $request($base . '?' . http_build_query(['option' => 'com_nicode_form_studio', 'view' => 'submissions'] + ($selection ? ['form_id' => $selection] : [])));
        $xp = $dom($page['body']);
        $assert($page['status'] === 200, 'Native reindex controls failed to render.');
        if ($selection) {
            foreach (['submission_id', 'received_from', 'received_to'] as $name) { $assert($xp->query('//form[@data-nfs-enqueue="reindex"]//input[@name="' . $name . '"]')->length === 1, 'Missing reindex selector: ' . $name); }
        } else { $assert($xp->query('//form[@data-nfs-enqueue="reindex"][@data-nfs-all-forms="true"]')->length === 1, 'Full reconstruction control missing.'); }
    }
    file_put_contents($root . '/build/native-reindex-results.json', json_encode(['passed' => true, 'form_id' => $formId, 'jobs' => $created, 'checks' => ['four native enqueue scopes', 'CSRF rejection for each mode', 'invalid UTC rejection', 'rendered selected-form and global controls'], 'timestamp' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Native reindex passed: all four enqueue scopes, CSRF, invalid UTC and administrator controls.\n";
} finally {
    foreach ($created as $id) { $response = $request($base . '?option=com_nicode_form_studio&task=job.cancel', ['id' => $id, $token => '1']); $assert($response['status'] === 200, 'Fixture job cancellation failed.'); }
}
