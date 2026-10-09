<?php
declare(strict_types=1);

$deletionFixture = $api('create', ['name' => 'Native permanent deletion fixture', 'alias' => 'native-delete-' . bin2hex(random_bytes(5))]);
$deletionId = (int) $deletionFixture['id'];
$api('deletionReview', query: ['id' => $deletionId], expected: 403);
$api('deactivate', ['id' => $deletionId, 'revision' => 0, 'state' => 'trashed']);
$deletionReview = $api('deletionReview', query: ['id' => $deletionId]);
$assert($deletionReview['state'] === 'trashed' && $deletionReview['responses'] === 0 && $deletionReview['files'] === 0, 'Native deletion counts or state failed.');
$deletePayload = ['id' => $deletionId, 'revision' => 1, 'confirmation' => $deletionReview['uuid']];
$api('deletePermanently', expected: 405);
$api('deletePermanently', $deletePayload, expected: 403, csrf: false);
$api('deletePermanently', array_replace($deletePayload, ['revision' => 0]), expected: 409);
$api('deletePermanently', array_replace($deletePayload, ['confirmation' => str_repeat('0', 36)]), expected: 403);
$deletionJob = $api('deletePermanently', $deletePayload)['job_id'];
$deletingRecord = $api('record', query: ['id' => $deletionId]);
$assert($deletingRecord['form']['state'] === 'deleting', 'Native form did not enter deletion state.');
$deletionAsset = (int) $deletingRecord['form']['asset_id'];
$jobApi('cancel', ['id' => $deletionJob], expected: 403);
$api('deactivate', ['id' => $deletionId, 'revision' => 2, 'state' => 'unpublished'], expected: 409);
$jobApi('enqueue', ['payload' => json_encode(['form_id' => $deletionId, 'type' => 'reindex'])], expected: 403);
$deletingPage = $request($base . '?option=com_nicode_form_studio&view=jobs');
$assert(!str_contains($deletingPage['body'], 'data-nfs-job-cancel="' . $deletionJob . '"'), 'Irreversible job offers cancellation.');
for ($batch = 0; $batch < 60; $batch++) {
    $deletionStatus = $jobApi('record', query: ['id' => $deletionJob]);
    if ($deletionStatus['state'] === 'completed') { break; }
    $jobApi('tick', []);
}
$assert($deletionStatus['state'] === 'completed', 'Native form deletion did not complete.');
$api('record', query: ['id' => $deletionId], expected: 403);
$verifyDatabase = new PDO('mysql:host=127.0.0.1;port=13367;dbname=formstudio_joomla;charset=utf8mb4', $configuration->user, $configuration->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$verifyAsset = $verifyDatabase->prepare('SELECT id FROM j6_assets WHERE id = ?'); $verifyAsset->execute([$deletionAsset]);
$assert($verifyAsset->fetchColumn() === false, 'Permanent deletion retained the Joomla asset.');
$assert((int) $verifyDatabase->query('SELECT COUNT(*) FROM j6_assets WHERE lft >= rgt')->fetchColumn() === 0, 'Native asset deletion damaged tree boundaries.');
$browserDeletion = $api('create', ['name' => 'Browser permanent deletion fixture', 'alias' => 'browser-delete-' . bin2hex(random_bytes(5))]);
$api('deactivate', ['id' => $browserDeletion['id'], 'revision' => 0, 'state' => 'trashed']);
file_put_contents($root . '/build/native-deletion-fixture.json', json_encode(['form_id' => (int) $browserDeletion['id'], 'deleted_form_id' => $deletionId, 'job_id' => $deletionJob, 'timestamp' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Native permanent deletion: review counts, CSRF/method/revision/confirmation, irreversible job, blocked reactivation and transactional Joomla asset removal passed.\n";
