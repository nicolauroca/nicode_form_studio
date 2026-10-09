<?php
declare(strict_types=1);

foreach ([['full', false], ['full', true], ['metadata', true], ['none', true]] as [$requestMode, $requestOptIn]) {
    $requestForm = $api('create', ['name' => 'Request metadata ' . $requestMode . ($requestOptIn ? ' enabled' : ' default'), 'alias' => 'request-metadata-' . bin2hex(random_bytes(6))]);
    $requestDraft = $api('record', query: ['id' => $requestForm['id']])['draft'];
    $requestDraft['privacy'] = ['store_ip' => $requestOptIn, 'store_user_agent' => $requestOptIn];
    $requestDraft['persistence'] = ['mode' => $requestMode];
    $requestRevision = $api('save', ['id' => $requestForm['id'], 'revision' => 0, 'draft' => $requestDraft])['revision'];
    $api('publish', ['id' => $requestForm['id'], 'revision' => $requestRevision]);
    $requestPage = $visitorRequest('http://127.0.0.1:13371/index.php?option=com_nicode_form_studio&view=form&id=' . $requestForm['id']);
    $requestXpath = $dom($requestPage['body']); $requestNode = $requestXpath->query('//form[@data-nfs-form]')->item(0);
    $assert($requestNode instanceof DOMElement, 'Request metadata fixture failed to render.');
    $requestPost = ['format' => 'json', 'ip' => '203.0.113.88', 'user_agent' => 'FORGED', 'request_metadata' => ['ip' => '203.0.113.88', 'user_agent' => 'FORGED']];
    foreach ($requestXpath->query('.//input[@type="hidden"]', $requestNode) as $input) { $requestPost[$input->getAttribute('name')] = $input->getAttribute('value'); }
    $requestResponse = $visitorRequest('http://127.0.0.1:13371' . $requestNode->getAttribute('action'), $requestPost, ['User-Agent: NFS privacy <script>literal</script>', 'X-Forwarded-For: 203.0.113.99']);
    $requestResult = json_decode($requestResponse['body'], true, flags: JSON_THROW_ON_ERROR);
    $assert($requestResponse['status'] === 200 && $requestResult['category'] === 'success', 'Request metadata submission failed.');
    $requestQuery = $prefillDb->prepare('SELECT id, canonical_payload FROM j6_nicode_form_studio_submissions WHERE form_id = ?'); $requestQuery->execute([$requestForm['id']]);
    $requestStored = $requestQuery->fetch(PDO::FETCH_ASSOC);
    if ($requestMode === 'none') { $assert($requestStored === false, 'No-storage mode persisted request metadata.'); continue; }
    $requestExpected = $requestOptIn ? ['ip' => '127.0.0.1', 'user_agent' => 'NFS privacy <script>literal</script>'] : [];
    $requestPayload = json_decode($requestStored['canonical_payload'], true, flags: JSON_THROW_ON_ERROR);
    $assert(($requestPayload['request_metadata'] ?? []) === $requestExpected, 'Transport metadata was forged or opt-in ignored.');
    $requestDetail = $submissionApi('record', ['form_id' => $requestForm['id'], 'id' => $requestStored['id']]);
    $assert($requestDetail['request_metadata'] === [] && $requestDetail['request_metadata_masked'] === $requestOptIn, 'Native ordinary detail leaked request metadata.');
    $requestRevealed = $submissionApi('reveal', payload: ['form_id' => $requestForm['id'], 'id' => (int) $requestStored['id']]);
    $assert($requestRevealed['request_metadata'] === $requestExpected, 'Native explicit reveal lost request metadata.');
    if ($requestOptIn) {
        $requestAudit = array_values(array_filter($requestRevealed['audit'], static fn (array $event): bool => $event['event_type'] === 'submission.reveal_sensitive'));
        $assert(count($requestAudit) === 1 && json_decode($requestAudit[0]['safe_metadata'], true) === ['fields' => 0, 'request_metadata_items' => 2], 'Native audit omitted request metadata counts or included private values.');
    }
    if ($requestOptIn && $requestMode === 'full') { file_put_contents($root . '/build/native-request-metadata-fixture.json', json_encode(['form_id' => $requestForm['id'], 'id' => (int) $requestStored['id']], JSON_THROW_ON_ERROR)); }
}
echo "Native request metadata: opt-in, trusted transport, forged POST/forwarded header rejection, protected reveal and no-storage exclusion passed.\n";
