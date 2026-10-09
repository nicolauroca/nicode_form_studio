<?php
declare(strict_types=1);

$metadataForm = $forms->create('Request metadata', 'request-metadata-' . bin2hex(random_bytes(5)), 1);
$metadataDraft = $forms->draft($metadataForm);
$metadataRaw = ['ip' => '2001:db8::1', 'user_agent' => 'Private browser <script>'];
$metadataReader = new Nicode\FormStudio\Application\SubmissionReader($submissions, $forms, $connection, static fn (int $actor, int $form, string $permission): bool => $actor === 1 || $permission !== 'formstudio.submissions.view_sensitive');
foreach ([['full', false], ['full', true], ['metadata', true], ['none', true]] as [$metadataMode, $metadataOptIn]) {
    $metadataDraft['privacy'] = ['store_ip' => $metadataOptIn, 'store_user_agent' => $metadataOptIn];
    $metadataDraft['persistence'] = ['mode' => $metadataMode];
    $metadataRevision = $forms->saveDraft($metadataForm, (int) $forms->get($metadataForm)['draft_revision'], $metadataDraft, 1);
    $metadataVersion = $forms->publish($metadataForm, $metadataRevision, 1);
    $metadataSpec = $forms->version($metadataForm, $metadataVersion);
    $metadataAttempt = hash('sha256', random_bytes(32));
    $metadataSubmission = $submissions->persist($metadataForm, $metadataVersion, $metadataSpec, [], $metadataAttempt, ['request_metadata' => $metadataRaw]);
    $metadataPayload = json_decode($submissions->get($metadataForm, $metadataSubmission->id)['canonical_payload'], true);
    $metadataExpected = $metadataOptIn && $metadataMode !== 'none' ? $metadataRaw : [];
    if (($metadataPayload['request_metadata'] ?? []) !== $metadataExpected) { throw new RuntimeException('Request metadata opt-in bypass.'); }
    $metadataReplay = $submissions->persist($metadataForm, $metadataVersion, $metadataSpec, [], $metadataAttempt, ['request_metadata' => ['ip' => '127.0.0.1']]);
    if (!$metadataReplay->replayed || $metadataReplay->id !== $metadataSubmission->id || json_decode($submissions->get($metadataForm, $metadataSubmission->id)['canonical_payload'], true) !== $metadataPayload) { throw new RuntimeException('Retry replaced original request metadata.'); }
    $metadataMasked = $metadataReader->read($metadataForm, $metadataSubmission->id, 1);
    if ($metadataMasked['request_metadata'] !== [] || $metadataMasked['request_metadata_masked'] !== ($metadataExpected !== [])) { throw new RuntimeException('Ordinary read leaked request metadata.'); }
    try { $metadataReader->read($metadataForm, $metadataSubmission->id, 2, 'view', true); throw new RuntimeException('Request metadata ACL bypass.'); } catch (DomainException) {}
    $metadataVisible = $metadataReader->read($metadataForm, $metadataSubmission->id, 1, 'view', true);
    if ($metadataVisible['request_metadata'] !== $metadataExpected || $metadataReader->read($metadataForm, $metadataSubmission->id, 1, 'export', true)['request_metadata'] !== []) { throw new RuntimeException('Request metadata reveal/export policy failed.'); }
    $metadataAudit = $connection->rows('SELECT safe_metadata FROM ' . $connection->table('audit_log') . ' WHERE submission_uuid = :uuid', [':uuid' => $metadataSubmission->uuid]);
    if (count($metadataAudit) !== ($metadataExpected === [] ? 0 : 1) || str_contains(json_encode($metadataAudit), 'Private browser')) { throw new RuntimeException('Metadata reveal audit missing or leaked values.'); }
    if ($metadataExpected !== [] && json_decode($metadataAudit[0]['safe_metadata'], true) !== ['fields' => 0, 'request_metadata_items' => 2]) { throw new RuntimeException('Metadata reveal audit miscounted exposed data.'); }
    $maintenance->apply($metadataForm, $metadataSubmission->id, 1, 'anonymize');
    if (isset(json_decode($submissions->get($metadataForm, $metadataSubmission->id)['canonical_payload'], true)['request_metadata'])) { throw new RuntimeException('Anonymization retained request metadata.'); }
}
echo "Request metadata opt-in, persistence modes, replay, sensitive reveal ACL/audit, export exclusion and anonymization verified.\n";
