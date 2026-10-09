<?php
declare(strict_types=1);

// Internal repository fixture only: compiler publication remains guarded.
$rpForm = $forms->create('Repeated persistence', 'repeated-' . bin2hex(random_bytes(5)), 1);
$rpData = $forms->draft($rpForm);
[$rpGroup, $rpText, $rpTransient, $rpFile, $rpOne, $rpTwo] = array_map(static fn () => Nicode\FormStudio\Domain\Uuid::create(), range(1, 6));
$rpData['elements'] = [['uuid' => $rpGroup, 'type' => 'repeatable-group', 'repeat' => ['min' => 1, 'max' => 2]]];
$rpData['fields'] = [
    ['uuid' => $rpText, 'name' => 'text', 'type' => 'text', 'index' => true, 'config' => ['max_length' => 255]],
    ['uuid' => $rpTransient, 'name' => 'temporary', 'type' => 'text', 'persist' => false],
    ['uuid' => $rpFile, 'name' => 'file', 'type' => 'file', 'config' => ['extensions' => ['txt'], 'mime_types' => ['text/plain'], 'max_bytes' => 100]],
];
foreach ($rpData['fields'] as $field) { $rpData['elements'][] = ['uuid' => $field['uuid'], 'type' => 'field', 'parent_uuid' => $rpGroup]; }
$rpSpec = new Nicode\FormStudio\Domain\FormSpec($rpData);
$rpVersion = $connection->insert('form_versions', ['form_id' => $rpForm, 'revision' => 1, 'schema_version' => '1.0', 'spec' => Nicode\FormStudio\Domain\CanonicalJson::encode($rpData), 'hash' => $rpSpec->hash, 'published_at' => gmdate('Y-m-d H:i:s'), 'published_by' => 1, 'comment' => 'Internal test snapshot', 'revoked_at' => null]);
$rpRows = [$rpGroup => [$rpTwo, $rpOne]];
$rpKey = static fn (string $field, string $row): string => $rpGroup . '/' . $row . '/' . $field;
$rpRaw = [$rpKey($rpText, $rpOne) => ' Uno ñ ', $rpKey($rpText, $rpTwo) => 'Dos', $rpKey($rpTransient, $rpOne) => 'action only'];
$rpReceipt = ['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'name' => 'a.txt', 'mime' => 'text/plain', 'size' => 3];
$rpFileObject = new Nicode\FormStudio\Storage\StoredFile('private', 'repeated-' . bin2hex(random_bytes(12)), 3, hash('sha256', 'abc'));
$rpContext = ['files' => [['field_address' => $rpKey($rpFile, $rpOne), 'receipt' => $rpReceipt, 'file' => $rpFileObject]]];
$rpValidated = $validationEngine->validateInstances($rpSpec, $rpRows, $rpRaw, [$rpKey($rpFile, $rpOne) => $rpReceipt]);
if (!$rpValidated->valid()) { throw new RuntimeException('Repeated fixture validation failed.'); }
$rpAttempt = hash('sha256', random_bytes(32));
$rpSaved = $submissions->persistInstances($rpForm, $rpVersion, $rpSpec, $rpRows, $rpValidated, $rpAttempt, $rpContext);
$rpOriginal = $submissions->get($rpForm, $rpSaved->id)['canonical_payload'];
$rpPayload = json_decode($rpOriginal, true, flags: JSON_THROW_ON_ERROR);
if ($rpPayload['instances'] !== $rpRows || $rpPayload['values'][$rpKey($rpText, $rpOne)] !== 'Uno ñ' || isset($rpPayload['values'][$rpKey($rpTransient, $rpOne)])) { throw new RuntimeException('Repeated canonical payload or persistence policy mismatch.'); }
$rpIndexes = $connection->rows('SELECT field_uuid, instance_path, instance_hash, ordinal, value_keyword FROM ' . $connection->table('submission_index') . ' WHERE submission_id = :id ORDER BY instance_path', [':id' => $rpSaved->id]);
if (count($rpIndexes) !== 2) { throw new RuntimeException('Repeated index count mismatch.'); }
foreach ($rpIndexes as $entry) {
    if ($entry['field_uuid'] !== $rpText || (int) $entry['ordinal'] !== 0 || $entry['instance_hash'] !== hash('sha256', $entry['instance_path']) || !in_array($entry['instance_path'], [$rpGroup . '/' . $rpOne, $rpGroup . '/' . $rpTwo], true)) { throw new RuntimeException('Repeated index lost scope identity.'); }
}
$rpStoredFile = $connection->row('SELECT * FROM ' . $connection->table('submission_files') . ' WHERE submission_id = :id', [':id' => $rpSaved->id]);
if ($rpStoredFile['field_uuid'] !== $rpFile || $rpStoredFile['instance_path'] !== $rpGroup . '/' . $rpOne || $rpStoredFile['instance_hash'] !== hash('sha256', $rpGroup . '/' . $rpOne)) { throw new RuntimeException('Stored file lost instance ownership.'); }
$rpReplay = $submissions->findReplayInstances($rpForm, $rpVersion, $rpSpec, $rpRows, $rpValidated, $rpAttempt, $rpContext);
if (!$rpReplay?->replayed || $rpReplay->id !== $rpSaved->id || !$submissions->persistInstances($rpForm, $rpVersion, $rpSpec, $rpRows, $rpValidated, $rpAttempt, $rpContext)->replayed) { throw new RuntimeException('Repeated replay was not reused.'); }
$rpReject = static function (callable $operation): void {
    try { $operation(); } catch (DomainException|InvalidArgumentException $expected) { return; }
    throw new RuntimeException('Invalid repeated persistence accepted.');
};
$rpChanged = $validationEngine->validateInstances($rpSpec, $rpRows, array_replace($rpRaw, [$rpKey($rpTransient, $rpOne) => 'changed']), [$rpKey($rpFile, $rpOne) => $rpReceipt]);
$rpReject(fn () => $submissions->persistInstances($rpForm, $rpVersion, $rpSpec, $rpRows, $rpChanged, $rpAttempt, $rpContext));
$rpReject(fn () => $submissions->findReplayInstances($rpForm, $rpVersion, $rpSpec, [$rpGroup => [$rpOne, $rpTwo]], $rpValidated, $rpAttempt, $rpContext));
$submissions->reindex($rpForm, $rpSaved->id, $rpSpec);
if ($submissions->get($rpForm, $rpSaved->id)['canonical_payload'] !== $rpOriginal || $connection->rows('SELECT field_uuid, instance_path, instance_hash, ordinal, value_keyword FROM ' . $connection->table('submission_index') . ' WHERE submission_id = :id ORDER BY instance_path', [':id' => $rpSaved->id]) !== $rpIndexes) { throw new RuntimeException('Repeated reindex changed payload or scope.'); }
// A file-key conflict occurs after response and index writes; all must roll back.
$rpFailedAttempt = hash('sha256', random_bytes(32)); $rpFailed = false;
try { $submissions->persistInstances($rpForm, $rpVersion, $rpSpec, $rpRows, $rpValidated, $rpFailedAttempt, $rpContext); }
catch (Joomla\Database\Exception\ExecutionFailureException) { $rpFailed = true; }
if (!$rpFailed || $connection->row('SELECT id FROM ' . $connection->table('attempts') . ' WHERE form_id = :form AND attempt_hash = :hash', [':form' => $rpForm, ':hash' => $rpFailedAttempt]) !== null || count($connection->rows('SELECT id FROM ' . $connection->table('submissions') . ' WHERE form_id = :form', [':form' => $rpForm])) !== 1) { throw new RuntimeException('Failed repeated transaction left partial data.'); }
// New upload receipts for the same bytes replay without attaching another file.
$rpRetryReceipt = array_replace($rpReceipt, ['uuid' => Nicode\FormStudio\Domain\Uuid::create()]);
$rpRetryValidation = $validationEngine->validateInstances($rpSpec, $rpRows, $rpRaw, [$rpKey($rpFile, $rpOne) => $rpRetryReceipt]);
$rpRetryContext = ['files' => [['field_address' => $rpKey($rpFile, $rpOne), 'receipt' => $rpRetryReceipt, 'file' => new Nicode\FormStudio\Storage\StoredFile('private', 'retry-' . bin2hex(random_bytes(12)), 3, $rpFileObject->checksum)]]];
if (!$submissions->persistInstances($rpForm, $rpVersion, $rpSpec, $rpRows, $rpRetryValidation, $rpAttempt, $rpRetryContext)->replayed || count($connection->rows('SELECT id FROM ' . $connection->table('submission_files') . ' WHERE submission_id = :id', [':id' => $rpSaved->id])) !== 1) { throw new RuntimeException('Repeated file retry attached another object.'); }
foreach (['metadata', 'none'] as $offset => $mode) {
    $modeData = $rpData; $modeData['persistence']['mode'] = $mode; $modeSpec = new Nicode\FormStudio\Domain\FormSpec($modeData);
    $modeVersion = $connection->insert('form_versions', ['form_id' => $rpForm, 'revision' => $offset + 2, 'schema_version' => '1.0', 'spec' => Nicode\FormStudio\Domain\CanonicalJson::encode($modeData), 'hash' => $modeSpec->hash, 'published_at' => gmdate('Y-m-d H:i:s'), 'published_by' => 1, 'comment' => 'Internal mode snapshot', 'revoked_at' => null]);
    $modeAttempt = hash('sha256', random_bytes(32));
    $modeSaved = $submissions->persistInstances($rpForm, $modeVersion, $modeSpec, $rpRows, $rpValidated, $modeAttempt, $rpContext + ['user_id' => 1]);
    $modeRow = $submissions->get($rpForm, $modeSaved->id); $modePayload = json_decode($modeRow['canonical_payload'], true, flags: JSON_THROW_ON_ERROR);
    if ($modePayload['values'] !== [] || $modePayload['instances'] !== [] || ($mode === 'none' && $modeRow['user_id'] !== null)) { throw new RuntimeException('Repeated mode retained private data.'); }
    $submissions->reindex($rpForm, $modeSaved->id, $modeSpec);
    foreach (['submission_index', 'submission_files'] as $table) {
        if ($connection->rows('SELECT id FROM ' . $connection->table($table) . ' WHERE submission_id = :id', [':id' => $modeSaved->id]) !== []) { throw new RuntimeException('Repeated mode retained indexes/files.'); }
    }
    if ($mode === 'none') {
        $submissions->completeAttempt($rpForm, $modeAttempt, ['status' => 'ok'], true);
        if (!$submissions->findReplayInstances($rpForm, $modeVersion, $modeSpec, $rpRows, $rpValidated, $modeAttempt, $rpContext)?->replayed || $submissions->response($rpForm, $modeAttempt) !== ['status' => 'ok']) { throw new RuntimeException('Repeated no-store completion lost safe replay.'); }
    }
}
echo "Repeated repository: canonical rows, field policy, scoped indexes/files, replay identity, reindex immutability and transactional rollback passed.\n";
