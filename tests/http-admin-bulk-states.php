<?php
declare(strict_types=1);
// This is the real authenticated Joomla job controller and worker. Keep reviewed
// last so the following privacy fixture continues to target these same two rows.
$bulkStates = ['new', 'viewed', 'processed', 'error', 'archived', 'spam', 'reviewed'];
$bulkRecord = static function (int $id) use ($request, $base, $jobFixture, $assert): array {
    $response = $request($base . '?' . http_build_query(['option' => 'com_nicode_form_studio', 'task' => 'submission.record', 'form_id' => $jobFixture['form_id'], 'id' => $id]));
    $assert($response['status'] === 200, 'Bulk state readback failed.');
    return json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR)['data'];
};
$bulkOriginals = array_map($bulkRecord, $jobFixture['response_ids']);
foreach ([$responsesPage['body'], $retryDetail['body']] as $html) {
    $dom = new DOMDocument(); $prior = libxml_use_internal_errors(true);
    try { $dom->loadHTML($html); } finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
    $xpath = new DOMXPath($dom);
    $options = $xpath->query('//form[@data-nfs-state or @data-operation="state"]//select[@name="state"]/option');
    $actual = [];
    foreach ($options as $option) {
        $actual[] = $option->getAttribute('value');
        $assert(trim($option->textContent) !== '' && !str_contains($option->textContent, 'COM_NICODE_'), 'Administrative state label missing or untranslated.');
    }
    $expected = $bulkStates; sort($expected); sort($actual);
    $assert($actual === $expected, 'Native single/bulk selector omitted or duplicated a state.');
}
$bulkInput = ['form_id' => $jobFixture['form_id'], 'type' => 'submission-bulk', 'operation' => 'state', 'query' => $jobQuery];
$jobApi('enqueue', ['payload' => json_encode($bulkInput + ['state' => 'invalid'])], expected: 422);
$jobApi('enqueue', ['payload' => json_encode($bulkInput + ['state' => 'processed'])], expected: 403, csrf: false);
foreach ($bulkStates as $state) {
    $stateJob = $runNativeJob($bulkInput + ['state' => $state]);
    $record = $jobApi('record', query: ['id' => $stateJob]);
    $assert((int) $record['processed'] === 2 && (int) $record['failed'] === 0, 'Bulk state ignored criteria or failed a transition.');
    foreach ($jobFixture['response_ids'] as $position => $responseId) {
        $after = $bulkRecord($responseId); $before = $bulkOriginals[$position];
        $assert($after['state'] === ($position < 2 ? $state : $before['state']), 'Native bulk state changed the wrong responses.');
        foreach (['values', 'action_status', 'form_version_id', 'uuid', 'actions'] as $key) {
            $assert($after[$key] === $before[$key], 'Native bulk state changed canonical answers, history or action results.');
        }
    }
}
echo "Native bulk states: all seven states, filtered worker execution, canonical/action preservation, single/bulk selector labels, invalid-state and CSRF rejection passed.\n";
