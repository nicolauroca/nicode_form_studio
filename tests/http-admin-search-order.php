<?php
declare(strict_types=1);

foreach (['received_at_asc', 'received_at_desc', 'id_asc', 'id_desc'] as $sort) {
    $orderedQuery = ['filters' => ['form_id' => $validationForm['id']], 'sort' => $sort, 'limit' => 1];
    $firstOrdered = $submissionApi('search', ['query' => json_encode($orderedQuery)]);
    $assert(count($firstOrdered['rows']) === 1 && is_string($firstOrdered['next_cursor']), 'Ordered HTTP fixture needs a continuation.');
    $secondOrdered = $submissionApi('search', ['query' => json_encode($orderedQuery + ['cursor' => $firstOrdered['next_cursor']])]);
    $assert(count($secondOrdered['rows']) === 1 && $secondOrdered['next_cursor'] === null, 'HTTP sorted cursor failed.');
    $firstId = (int) $firstOrdered['rows'][0]['id']; $secondId = (int) $secondOrdered['rows'][0]['id'];
    $assert(str_ends_with($sort, '_asc') ? $firstId < $secondId : $firstId > $secondId, 'HTTP order reversed.');
    $orderedQuery['sort'] = $sort === 'id_asc' ? 'id_desc' : 'id_asc'; $orderedQuery['cursor'] = $firstOrdered['next_cursor'];
    $submissionApi('search', ['query' => json_encode($orderedQuery)], expected: 422);
}
$submissionApi('search', ['query' => json_encode(['sort' => ['unsafe']])], expected: 422);
$orderedPresetQuery = ['filters' => ['form_id' => $validationForm['id']], 'fields' => [], 'columns' => [], 'sort' => 'id_asc'];
$orderedPreset = $submissionApi('saveView', payload: ['name' => 'Ascending native acceptance', 'query' => $orderedPresetQuery]);
$assert($submissionApi('savedView', ['id' => $orderedPreset['id']])['query'] === $orderedPresetQuery, 'Saved view lost selected sort.');
$orderedPage = $request($base . '?option=com_nicode_form_studio&view=submissions&preset=' . $orderedPreset['id']);
$orderedDom = $dom($orderedPage['body']);
$assert($orderedPage['status'] === 200 && $orderedDom->query('//select[@name="sort"]/option[@value="id_asc" and @selected]')->length === 1 && $orderedDom->query('//th[@aria-sort="ascending"]')->length === 1, 'Native sort selection/accessibility state missing.');
$orderedJobData = json_decode($orderedDom->query('//script[@data-nfs-job-query]')->item(0)->textContent, true, 512, JSON_THROW_ON_ERROR);
$assert($orderedJobData['query']['sort'] === 'id_asc', 'Native export lost visible order.');
file_put_contents($root . '/build/native-order-fixture.json', json_encode(['preset' => $orderedPreset['id'], 'form_id' => $validationForm['id']], JSON_THROW_ON_ERROR));
echo "Native search order HTTP: all four orders/cursors, sort mismatch, private preset round-trip and accessible native selection passed.\n";
