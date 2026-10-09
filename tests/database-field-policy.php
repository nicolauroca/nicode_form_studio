<?php
declare(strict_types=1);
$policyForm = $forms->create('Field policy acceptance', 'field-policy-' . bin2hex(random_bytes(5)), 1);
$policyDraft = $forms->draft($policyForm); $policyIds = []; $policyValues = [];
foreach ([
    'public' => ['index' => true, 'include_export' => false],
    'ephemeral' => ['persist' => false, 'index' => true, 'include_email' => false],
    'private' => ['sensitive' => true],
    'opted' => ['sensitive' => true, 'index' => true, 'allow_sensitive_index' => true, 'include_email' => true, 'include_export' => true],
    'password' => ['persist' => true, 'include_email' => true, 'include_export' => true],
] as $name => $flags) {
    $uuid = Nicode\FormStudio\Domain\Uuid::create(); $policyIds[$name] = $uuid; $policyValues[$uuid] = 'sentinel-' . $name;
    $policyDraft['elements'][] = ['uuid' => $uuid, 'type' => 'field', 'parent_uuid' => null];
    $policyDraft['fields'][] = ['uuid' => $uuid, 'name' => $name, 'type' => $name === 'password' ? 'password' : 'text', 'config' => []] + $flags;
}
$policyRevision = $forms->saveDraft($policyForm, 0, $policyDraft, 1); $policyVersion = $forms->publish($policyForm, $policyRevision, 1); $policySpec = $forms->version($policyForm, $policyVersion);
$policySubmission = $submissions->persist($policyForm, $policyVersion, $policySpec, $policyValues, hash('sha256', random_bytes(32)));
$policyPayload = json_decode($submissions->get($policyForm, $policySubmission->id)['canonical_payload'], true, flags: JSON_THROW_ON_ERROR)['values'];
foreach ($policyIds as $name => $uuid) { if (array_key_exists($uuid, $policyPayload) !== !in_array($name, ['ephemeral', 'password'], true)) { throw new RuntimeException('Canonical storage ignored field persistence or password exclusion.'); } }
$policyIndex = array_column($connection->rows('SELECT field_uuid FROM ' . $connection->table('submission_index') . ' WHERE submission_id = :id', [':id' => $policySubmission->id]), 'field_uuid');
sort($policyIndex); $expectedPolicyIndex = [$policyIds['public'], $policyIds['opted']]; sort($expectedPolicyIndex);
if ($policyIndex !== $expectedPolicyIndex) { throw new RuntimeException('Field index policy did not match stored values and explicit sensitive consent.'); }
$policyTokens = (new Nicode\FormStudio\Actions\ActionContext($policySpec, $policyValues, 'reference', 'date'))->emailTokens();
foreach ($policyIds as $name => $uuid) { if (isset($policyTokens['field.' . $uuid . '.value']) !== in_array($name, ['public', 'opted'], true)) { throw new RuntimeException('Email inclusion ignored field policy.'); } }
$policyReader = new Nicode\FormStudio\Application\SubmissionReader($submissions, $forms, $connection, $authorizeRead);
$policyMasked = $policyReader->read($policyForm, $policySubmission->id, 2);
if (array_keys($policyMasked['values']) !== [$policyIds['public']] || count($policyMasked['masked']) !== 2) { throw new RuntimeException('Sensitive field values escaped default masking.'); }
$policyExport = $policyReader->read($policyForm, $policySubmission->id, 1, 'export', true)['values'];
if ($policyExport !== [$policyIds['opted'] => 'sentinel-opted']) { throw new RuntimeException('Export ignored explicit inclusion/exclusion or password policy.'); }
if ($policyReader->read($policyForm, $policySubmission->id, 2, 'export')['values'] !== []) { throw new RuntimeException('Sensitive export bypassed actor ACL.'); }
echo "Field policy matrix: persistence, derived indexes, sensitive defaults/explicit inclusion, email tokens, password overrides and permission-gated exports passed.\n";
