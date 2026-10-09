<?php
declare(strict_types=1);
// Shares the real logged-in session and assertions of http-admin.php.
$submissionApi = static function (string $task, array $query = [], ?array $payload = null, int $expected = 200, bool $csrf = true) use ($base, $request, $token, $assert): array {
    $response = $request($base . '?' . http_build_query(['option' => 'com_nicode_form_studio', 'task' => 'submission.' . $task, 'format' => 'json'] + $query), $payload === null ? null : ['payload' => json_encode($payload, JSON_THROW_ON_ERROR)] + ($csrf ? [$token => '1'] : []));
    $assert($response['status'] === $expected, 'Unexpected submission status for ' . $task . ': ' . $response['status']);
    $assert(str_contains(strtolower($response['headers']), 'no-store'), 'Response data may be cached.');
    $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    $assert(($decoded['ok'] ?? null) === ($expected === 200), 'Invalid submission API envelope.');
    return $decoded['data'] ?? $decoded;
};
$runtimeFixture = json_decode(file_get_contents($root . '/build/joomla-runtime-results.json'), true, 512, JSON_THROW_ON_ERROR);
$responseForm = $runtimeFixture['form_id'];
$responses = $submissionApi('search', ['query' => json_encode(['filters' => ['form_id' => $responseForm, 'id' => $runtimeFixture['submission_id']], 'limit' => 1])]);
$assert(count($responses['rows']) === 1 && !isset($responses['rows'][0]['canonical_payload']), 'Response header search failed.');
$responseId = (int) $responses['rows'][0]['id'];
$assert(count($responses['filter_fields']) === 1 && $responses['filter_fields'][0]['uuid'] === $runtimeFixture['field_uuid'], 'Indexed schema metadata includes private or unindexed fields.');
$filtered = $submissionApi('search', ['query' => json_encode(['filters' => ['form_id' => $responseForm], 'fields' => [['field' => $runtimeFixture['field_uuid'], 'operator' => 'contains', 'value' => 'Synthetic native']]])]);
$assert(count($filtered['rows']) === 1, 'Scoped field search failed.');
$columnsResult = $submissionApi('search', ['query' => json_encode(['filters' => ['form_id' => $responseForm, 'id' => $responseId], 'columns' => [$runtimeFixture['field_uuid']]])]);
$assert($columnsResult['rows'][0]['cells'][$runtimeFixture['field_uuid']]['value'] === 'Synthetic native composition answer', 'Canonical answer column failed.');
$presetQuery = ['filters' => ['form_id' => $responseForm], 'fields' => [['field' => $runtimeFixture['field_uuid'], 'operator' => 'contains', 'value' => 'Synthetic native']], 'columns' => [$runtimeFixture['field_uuid']]];
$presetPayload = ['name' => 'Private HTTP view', 'query' => $presetQuery, 'owner_id' => 999999];
$submissionApi('saveView', payload: $presetPayload, expected: 403, csrf: false);
$savedPreset = $submissionApi('saveView', payload: $presetPayload);
$loadedPreset = $submissionApi('savedView', ['id' => $savedPreset['id']]);
$assert($loadedPreset['query'] === $presetQuery, 'Saved view changed filter semantics.');
$presetPage = $request($base . '?' . http_build_query(['option' => 'com_nicode_form_studio', 'view' => 'submissions', 'preset' => $savedPreset['id']]));
$assert($presetPage['status'] === 200 && str_contains($presetPage['body'], 'Synthetic native composition answer'), 'Saved preset failed to render answer column.');
$submissionApi('removeView', payload: ['id' => $savedPreset['id']]);
$submissionApi('savedView', ['id' => $savedPreset['id']], expected: 404);
$record = $submissionApi('record', ['form_id' => $responseForm, 'id' => $responseId]);
$assert($record['form_id'] === $responseForm && isset($record['values'][$runtimeFixture['field_uuid']]), 'Response detail lost its canonical snapshot.');
$submissionApi('record', ['form_id' => $id, 'id' => $responseId], expected: 404);
$submissionApi('record', ['form_id' => $responseForm, 'id' => $responseId . 'junk'], expected: 422);
$submissionApi('search', ['query' => json_encode(['filters' => ['received_from' => '2026-02-30 00:00:00']])], expected: 422);
$submissionApi('reveal', expected: 405);
$submissionApi('reveal', payload: ['form_id' => $responseForm, 'id' => $responseId], expected: 403, csrf: false);
$revealed = $submissionApi('reveal', payload: ['form_id' => $responseForm, 'id' => $responseId]);
$assert(count($record['masked']) === 1 && !in_array('Synthetic restricted answer', $record['values'], true) && in_array('Synthetic restricted answer', $revealed['values'], true), 'Sensitive values leaked or explicit reveal failed.');
$originalState = $record['state']; $changedState = $originalState === 'reviewed' ? 'new' : 'reviewed';
$mutation = ['form_id' => $responseForm, 'id' => $responseId, 'expected' => $originalState, 'state' => $changedState];
$submissionApi('state', payload: $mutation, expected: 403, csrf: false);
$submissionApi('state', payload: $mutation);
$submissionApi('state', payload: $mutation, expected: 409);
$submissionApi('state', payload: ['form_id' => $responseForm, 'id' => $responseId, 'expected' => $changedState, 'state' => $originalState]);
$currentState=$originalState;
foreach(['spam','archived','viewed','reviewed','processed','error','new',$originalState] as $nextState) {
    $submissionApi('state',payload:['form_id'=>$responseForm,'id'=>$responseId,'expected'=>$currentState,'state'=>$nextState]);
    $currentState=$nextState; $stateRecord=$submissionApi('record',['form_id'=>$responseForm,'id'=>$responseId]);
    $assert($stateRecord['state']===$nextState && $stateRecord['values']===$record['values'] && $stateRecord['action_status']===$record['action_status'], 'Native state transition changed answers/action outcome.');
    $stateSearch=$submissionApi('search',['query'=>json_encode(['filters'=>['form_id'=>$responseForm,'id'=>$responseId,'state'=>$nextState]])]);
    $assert(count($stateSearch['rows'])===1 && $stateSearch['rows'][0]['state']===$nextState,'Native state filter lost updated response.');
}
$submissionApi('state',payload:['form_id'=>$responseForm,'id'=>$responseId,'expected'=>$originalState,'state'=>'invalid'],expected:422);
$submissionApi('note', payload: ['form_id' => $id, 'id' => $responseId, 'body' => 'cross-form'], expected: 404);
$submissionApi('note', payload: ['form_id' => $responseForm, 'id' => $responseId, 'body' => 'Literal <script> note from HTTP fixture']);
$withNote = $submissionApi('record', ['form_id' => $responseForm, 'id' => $responseId]);
$assert($withNote['notes'][0]['body'] === 'Literal <script> note from HTTP fixture' && $withNote['state'] === $originalState, 'State/notes write failed.');
$submissionApi('download', expected: 405);
$submissionApi('download', payload: [], expected: 403, csrf: false);
foreach (['submissions' => ['form_id' => $responseForm], 'submission' => ['form_id' => $responseForm, 'id' => $responseId]] as $view => $query) {
    $rendered = $request($base . '?' . http_build_query(['option' => 'com_nicode_form_studio', 'view' => $view] + $query));
    $assert($rendered['status'] === 200 && str_contains($rendered['body'], $record['uuid']) && str_contains(strtolower($rendered['headers']), 'no-store'), 'Response view rendering failed: ' . $view);
}
file_put_contents($root . '/build/admin-submissions-http-results.json', json_encode(['passed' => true, 'timestamp' => gmdate(DATE_ATOM), 'checks' => ['header search', 'canonical detail', 'cross-form boundary', 'strict identity', 'date validation', 'POST and CSRF reveal', 'list/detail no-store views']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Native administrator response HTTP: search, detail, cross-form isolation, dates, reveal method/CSRF and rendered views passed.\n";
