<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Infrastructure\Database;

use Nicode\FormStudio\Domain\CanonicalJson;
use Nicode\FormStudio\Domain\FormSpec;
use Nicode\FormStudio\Domain\Uuid;
use Nicode\FormStudio\Search\IndexProjector;
use Nicode\FormStudio\Submission\PersistedSubmission;

final readonly class SubmissionRepository
{
    public function __construct(private Connection $db, private IndexProjector $projection, private string $fingerprintKey, private ?UploadJournal $uploads = null)
    {
        if (strlen($fingerprintKey) < 32) { throw new \InvalidArgumentException('Request fingerprint key must contain at least 32 bytes.'); }
    }

    public function persist(int $formId, int $versionId, FormSpec $spec, array $canonicalValues, string $attemptHash, array $context = []): PersistedSubmission
    {
        return $this->persistValues($formId, $versionId, $spec, $canonicalValues, $attemptHash, $context);
    }

    public function persistInstances(int $formId, int $versionId, FormSpec $spec, array $declarations, \Nicode\FormStudio\Validation\ValidationResult $validated, string $attemptHash, array $context = [], int $budget = 10000): PersistedSubmission
    {
        return $this->persistValues($formId, $versionId, $spec, $validated->values, $attemptHash, $context, $declarations, $validated, $budget);
    }

    private function persistValues(int $formId, int $versionId, FormSpec $spec, array $canonicalValues, string $attemptHash, array $context, ?array $declarations = null, ?\Nicode\FormStudio\Validation\ValidationResult $validated = null, int $budget = 10000): PersistedSubmission
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $attemptHash) !== 1) { throw new \InvalidArgumentException('Invalid idempotency key.'); }
        $definition = \Nicode\FormStudio\Translation\DefinitionTranslations::resolve($spec->toArray(), $context['locale'] ?? 'en-GB');
        $mode = $definition['persistence']['mode'] ?? 'full';
        if (!in_array($mode, ['full', 'metadata', 'none'], true)) { throw new \InvalidArgumentException('Unsupported persistence mode.'); }
        if ($mode === 'none') { $context['user_id'] = null; }
        $requestMetadata = \Nicode\FormStudio\Privacy\RequestMetadata::select($definition['privacy'] ?? [], $mode, $context['request_metadata'] ?? []);
        $now = gmdate('Y-m-d H:i:s');
        $selected = $declarations === null
            ? \Nicode\FormStudio\Submission\StoredValues::select($definition, $canonicalValues, $versionId, $now, $context['option_labels'] ?? [])
            : \Nicode\FormStudio\Submission\StoredValues::instances($spec, $declarations, $validated, $versionId, $now, $context['option_labels'] ?? [], $context['locale'] ?? 'en-GB', $budget);
        $persisted = $selected['values'];
        // Actions may consume non-persisted fields. Retries must match those values too.
        $requestHash = $declarations === null ? $this->fingerprint($versionId, $spec, $canonicalValues, $context)
            : (new \Nicode\FormStudio\Submission\RequestFingerprint($this->fingerprintKey))->instances($versionId, $spec, $declarations, $validated, $context, $budget);
        $existing = $this->attempt($formId, $attemptHash);
        if ($existing !== null) { return $this->replay($existing, $requestHash); }
        try {
            return $this->db->transaction(function () use ($formId, $versionId, $spec, $persisted, $selected, $attemptHash, $context, $now, $requestHash, $requestMetadata, $declarations, $budget): PersistedSubmission {
                if (($context['files'] ?? []) !== []) { $this->uploads?->guardPersistence(); }
                $form = $this->db->row('SELECT state, published_version_id FROM ' . $this->db->table('forms') . ' WHERE id = :id FOR UPDATE', [':id' => $formId]);
                if (!$form || $form['state'] === 'deleting') { throw new \DomainException('Form no longer accepts submissions.'); }
                $version = $this->db->row('SELECT hash FROM ' . $this->db->table('form_versions') . ' WHERE id = :version AND form_id = :form', [':version' => $versionId, ':form' => $formId]);
                if (!$version || !hash_equals($version['hash'], $spec->hash)) { throw new \DomainException('Submission snapshot does not belong to the requested form version.'); }
                $uuid = Uuid::create();
                $this->db->insert('attempts', ['attempt_hash' => $attemptHash, 'form_id' => $formId, 'form_version_id' => $versionId, 'request_hash' => $requestHash, 'state' => 'persisting', 'response' => null, 'submission_uuid' => $uuid, 'created_at' => $now, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400)]);
                $id = $this->db->insert('submissions', ['uuid' => $uuid, 'form_id' => $formId, 'form_version_id' => $versionId, 'state' => 'new', 'received_at' => $now, 'processed_at' => null,
                    'user_id' => $context['user_id'] ?? null, 'channel' => $context['channel'] ?? 'component', 'locale' => $context['locale'] ?? 'en-GB',
                    'canonical_payload' => CanonicalJson::encode((['schema_version' => '1.0'] + $selected) + ($requestMetadata === [] ? [] : ['request_metadata'=>$requestMetadata])),
                    'payload_schema_version' => '1.0', 'action_status' => 'pending', 'expires_at' => $context['expires_at'] ?? null, 'anonymized_at' => null, 'attempt_hash' => $attemptHash, 'index_pending' => 0]);
                $this->writeProjection($id, $formId, $versionId, $declarations === null ? $this->projection->project($spec, $persisted) : $this->projection->projectInstances($spec, $selected['instances'], $persisted, $budget));
                // An already accepted request can finish after a new publication/backfill.
                // Keep its original payload, but schedule its projection under the new policy.
                if ($form['published_version_id'] !== null && (int) $form['published_version_id'] !== $versionId) {
                    $active = $this->db->row('SELECT spec, published_by FROM ' . $this->db->table('form_versions') . ' WHERE id = :version AND form_id = :form', [':version' => (int) $form['published_version_id'], ':form' => $formId]);
                    if ($active !== null && \Nicode\FormStudio\Search\HistoricalIndexPolicy::signature($spec) !== \Nicode\FormStudio\Search\HistoricalIndexPolicy::signature(new FormSpec(json_decode($active['spec'], true, 512, JSON_THROW_ON_ERROR)))) {
                        $this->db->execute('UPDATE ' . $this->db->table('submissions') . ' SET index_pending = 1 WHERE id = :id', [':id' => $id]);
                        (new JobRepository($this->db))->enqueue('reindex', ['form_id' => $formId, 'submission_id' => $id], (int) $active['published_by']);
                    }
                }
                foreach ($context['files'] ?? [] as $entry) {
                    $fieldKey = $declarations === null ? $entry['field_uuid'] : $entry['field_address']; $receipt = $entry['receipt']; $object = $entry['file'];
                    if (!array_key_exists($fieldKey, $persisted)) { continue; }
                    $address = \Nicode\FormStudio\Domain\FieldAddress::fromKey($fieldKey);
                    $path = $address->instances === [] ? '' : substr($fieldKey, 0, -37);
                    $value = $persisted[$fieldKey]; $receipts = is_array($value) && array_is_list($value) ? $value : [$value];
                    if (!$object instanceof \Nicode\FormStudio\Storage\StoredFile || !in_array($receipt['uuid'], array_column($receipts, 'uuid'), true)) { throw new \DomainException('Stored upload does not match canonical receipt.'); }
                    $this->uploads?->consume($formId, $object, $entry['staging_token'] ?? null);
                    $this->db->insert('submission_files', ['uuid' => $receipt['uuid'], 'submission_id' => $id, 'field_uuid' => $address->field, 'instance_path' => $path, 'instance_hash' => hash('sha256', $path), 'provider' => $object->provider, 'storage_key' => $object->key, 'original_name' => $receipt['name'], 'mime' => $receipt['mime'], 'size_bytes' => $object->size, 'checksum' => $object->checksum, 'created_at' => $now]);
                }
                $this->db->execute('UPDATE ' . $this->db->table('attempts') . ' SET state = :state WHERE form_id = :form AND attempt_hash = :attempt', [':state' => 'persisted', ':form' => $formId, ':attempt' => $attemptHash]);
                return new PersistedSubmission($id, $uuid, false);
            });
        } catch (\Joomla\Database\Exception\ExecutionFailureException $error) {
            // A competing request may win the unique attempt constraint. Its committed
            // result is reused only when the request fingerprint matches exactly.
            $existing = $this->attempt($formId, $attemptHash);
            if ($existing !== null) { return $this->replay($existing, $requestHash); }
            throw $error;
        }
    }

    public function get(int $formId, int $submissionId): array
    {
        return $this->db->row('SELECT * FROM ' . $this->db->table('submissions') . ' WHERE id = :id AND form_id = :form', [':id' => $submissionId, ':form' => $formId]) ?? throw new \OutOfBoundsException('Submission not found.');
    }

    public function findReplay(int $formId, int $versionId, FormSpec $spec, array $values, string $attemptHash, array $context = []): ?PersistedSubmission
    {
        $row = $this->attempt($formId, $attemptHash);
        return $row === null ? null : $this->replay($row, $this->fingerprint($versionId, $spec, $values, $context));
    }
    public function response(int $formId, string $attemptHash): ?array
    {
        $row = $this->db->row('SELECT response FROM ' . $this->db->table('attempts') . " WHERE form_id = :form AND attempt_hash = :hash AND state IN ('completed', 'ephemeral_completed')", [':form' => $formId, ':hash' => $attemptHash]);
        return $row !== null && $row['response'] !== null ? json_decode($row['response'], true, 512, JSON_THROW_ON_ERROR) : null;
    }
    public function findReplayInstances(int $formId, int $versionId, FormSpec $spec, array $declarations, \Nicode\FormStudio\Validation\ValidationResult $validated, string $attemptHash, array $context = [], int $budget = 10000): ?PersistedSubmission
    {
        $hash = (new \Nicode\FormStudio\Submission\RequestFingerprint($this->fingerprintKey))->instances($versionId, $spec, $declarations, $validated, $context, $budget);
        $row = $this->attempt($formId, $attemptHash);
        return $row === null ? null : $this->replay($row, $hash);
    }
    public function completeAttempt(int $formId, string $attemptHash, array $response, bool $discardSubmission = false): void
    {
        if ($discardSubmission) {
            $this->db->transaction(function () use ($formId, $attemptHash, $response): void {
                // Align with action claims and expiry cleanup, including parent
                // FK locks acquired while deleting the ephemeral response.
                $this->db->row('SELECT id FROM ' . $this->db->table('forms') . ' WHERE id=:id FOR UPDATE', [':id' => $formId]);
                $row = $this->db->row('SELECT id, form_version_id FROM ' . $this->db->table('submissions') . ' WHERE form_id = :form AND attempt_hash = :hash FOR UPDATE', [':form' => $formId, ':hash' => $attemptHash]);
                if (!$row) { return; }
                $version = $this->db->row('SELECT spec FROM ' . $this->db->table('form_versions') . ' WHERE id = :id AND form_id = :form', [':id' => (int) $row['form_version_id'], ':form' => $formId]);
                if (!$version || (json_decode($version['spec'], true, 512, JSON_THROW_ON_ERROR)['persistence']['mode'] ?? 'full') !== 'none') { throw new \DomainException('Only explicit no-store responses can be discarded after processing.'); }
                $this->db->execute('UPDATE ' . $this->db->table('attempts') . " SET state = 'ephemeral_completed', response = :response WHERE form_id = :form AND attempt_hash = :hash AND state IN ('persisted', 'completed')", [':response' => CanonicalJson::encode($response), ':form' => $formId, ':hash' => $attemptHash]);
                foreach (['submission_index', 'submission_files', 'submission_notes', 'action_runs'] as $table) { $this->db->execute('DELETE FROM ' . $this->db->table($table) . ' WHERE submission_id = :id', [':id' => (int) $row['id']]); }
                $this->db->execute('DELETE FROM ' . $this->db->table('submissions') . ' WHERE id = :id', [':id' => (int) $row['id']]);
            });
            return;
        }
        $this->db->execute('UPDATE ' . $this->db->table('attempts') . " SET state = 'completed', response = :response WHERE form_id = :form AND attempt_hash = :hash AND state IN ('persisted', 'completed')", [':response' => CanonicalJson::encode($response), ':form' => $formId, ':hash' => $attemptHash]);
    }

    private function fingerprint(int $versionId, FormSpec $spec, array $values, array $context): string
    {
        return (new \Nicode\FormStudio\Submission\RequestFingerprint($this->fingerprintKey))->ordinary($versionId, $spec, $values, $context);
    }

    public function reindex(int $formId, int $submissionId, FormSpec $spec): void
    {
        $this->db->transaction(function () use ($formId, $submissionId, $spec): void {
            $row = $this->db->row('SELECT * FROM ' . $this->db->table('submissions') . ' WHERE id = :id AND form_id = :form FOR UPDATE', [':id' => $submissionId, ':form' => $formId]);
            if (!$row) { throw new \OutOfBoundsException('Submission not found.'); }
            $owner = $this->db->row('SELECT uuid FROM ' . $this->db->table('forms') . ' WHERE id = :id', [':id' => $formId]);
            if (!$owner || $owner['uuid'] !== $spec->toArray()['uuid']) { throw new \DomainException('Index schema belongs to another form.'); }
            $payload = json_decode($row['canonical_payload'], true, 512, JSON_THROW_ON_ERROR);
            $this->db->execute('DELETE FROM ' . $this->db->table('submission_index') . ' WHERE submission_id = :id', [':id' => $submissionId]);
            if ($row['anonymized_at'] === null) {
                $projection = array_key_exists('instances', $payload) ? $this->projection->projectInstances($spec, $payload['instances'], $payload['values']) : $this->projection->project($spec, $payload['values']);
                $this->writeProjection($submissionId, $formId, (int) $row['form_version_id'], $projection);
            }
            $this->db->execute('UPDATE ' . $this->db->table('submissions') . ' SET index_pending = 0 WHERE id = :id', [':id' => $submissionId]);
        });
    }

    private function writeProjection(int $submissionId, int $formId, int $versionId, array $projection): void
    {
        $rows = [];
        foreach ($projection as $value) {
            if (isset($value['field_address'])) {
                $address = \Nicode\FormStudio\Domain\FieldAddress::fromKey($value['field_address']);
                $path = $address->instances === [] ? '' : substr($address->key(), 0, -37);
                if ($address->field !== $value['field_uuid'] || $path !== $value['instance_path'] || !hash_equals(hash('sha256', $path), $value['instance_hash'])) { throw new \DomainException('Invalid addressed index projection.'); }
                unset($value['field_address']);
            }
            $rows[] = array_replace(['submission_id' => $submissionId, 'form_id' => $formId, 'form_version_id' => $versionId, 'field_uuid' => null, 'value_type' => null, 'value_keyword' => null, 'value_text' => null, 'value_integer' => null, 'value_decimal' => null, 'value_boolean' => null, 'value_date' => null, 'value_datetime' => null, 'ordinal' => 0], $value);
        }
        $this->db->insertMany('submission_index', $rows);
    }

    private function attempt(int $formId, string $hash): ?array
    {
        return $this->db->row('SELECT a.request_hash, a.state, s.id, COALESCE(s.uuid, a.submission_uuid) AS uuid FROM ' . $this->db->table('attempts') . ' a LEFT JOIN ' . $this->db->table('submissions') . ' s ON s.uuid = a.submission_uuid WHERE a.form_id = :form AND a.attempt_hash = :hash', [':form' => $formId, ':hash' => $hash]);
    }
    private function replay(array $row, string $hash): PersistedSubmission
    {
        if ($row['state'] === 'removed' || ($row['id'] === null && $row['state'] !== 'ephemeral_completed')) { throw new \DomainException('Submission attempt is no longer available.'); }
        if (!hash_equals($row['request_hash'], $hash)) { throw new \DomainException('Idempotency key already used with a different payload.'); }
        return new PersistedSubmission((int) $row['id'], $row['uuid'], true);
    }
}
