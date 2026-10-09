<?php
declare(strict_types=1);

// The including suite has already checked exact isolated database and HTTP host.
$historyDb = new PDO('mysql:host=127.0.0.1;port=13367;dbname=formstudio_joomla;charset=utf8mb4', $configuration->user, $configuration->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$historyInsert = $historyDb->prepare('INSERT INTO j6_nicode_form_studio_submission_notes (submission_id, created_by, created_at, body) VALUES (?, ?, ?, ?)');
$historyActorQuery = $historyDb->prepare('SELECT created_by FROM j6_nicode_form_studio_forms WHERE id = ?'); $historyActorQuery->execute([$responseForm]); $historyActor = (int) $historyActorQuery->fetchColumn();
$historyDb->beginTransaction();
try {
    for ($i = 0; $i < 103; $i++) { $historyInsert->execute([$responseId, $historyActor, '2026-09-27 12:00:00', 'Paged synthetic note ' . $i]); }
    $historyDb->commit();
} catch (Throwable $error) { $historyDb->rollBack(); throw $error; }
$historyQuery = ['form_id' => $responseForm, 'id' => $responseId];
$firstHistory = $submissionApi('record', $historyQuery);
$assert(count($firstHistory['notes']) === 100 && $firstHistory['notes_next_before'] > 0, 'HTTP history lacks bounded continuation.');
$nextHistory = $submissionApi('record', $historyQuery + ['notes_before' => $firstHistory['notes_next_before']]);
$assert(count($nextHistory['notes']) >= 3 && array_intersect(array_column($firstHistory['notes'], 'id'), array_column($nextHistory['notes'], 'id')) === [], 'HTTP history overlaps or loses continuation.');
$assert(array_column($firstHistory['actions'], 'id') === array_column($nextHistory['actions'], 'id'), 'Notes navigation changed action history.');
$submissionApi('record', $historyQuery + ['notes_before' => '1junk'], expected: 422);
$submissionApi('record', $historyQuery + ['notes_before' => '-1'], expected: 422);
$historyPage = $request($base . '?' . http_build_query(['option' => 'com_nicode_form_studio', 'view' => 'submission'] + $historyQuery));
$historyDom = $dom($historyPage['body']);
$historyLink = $historyDom->query('//a[@data-nfs-history-next="notes"]')->item(0);
$assert($historyPage['status'] === 200 && $historyLink instanceof DOMElement, 'Native history navigation absent.');
$historyNextUrl = 'http://127.0.0.1:13371' . explode('#', $historyLink->getAttribute('href'))[0];
$historyNextPage = $request($historyNextUrl);
$assert($historyNextPage['status'] === 200 && str_contains($historyNextPage['body'], 'Paged synthetic note 0') && str_contains($historyNextPage['body'], 'Most recent records'), 'Native history link does not reach older notes.');
file_put_contents($root . '/build/native-history-fixture.json', json_encode($historyQuery, JSON_THROW_ON_ERROR));
echo "Native response history HTTP: bounded notes, independent cursors, strict query parsing and working older/latest links passed.\n";
