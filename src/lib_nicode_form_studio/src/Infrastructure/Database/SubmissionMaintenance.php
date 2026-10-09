<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Infrastructure\Database;

use Nicode\FormStudio\Domain\CanonicalJson;
use Nicode\FormStudio\Domain\Uuid;

final readonly class SubmissionMaintenance
{
    public function __construct(private Connection $db, private JobRepository $jobs, private \Closure $authorize) {}
    public function apply(int $form, int $submission, int $actor, string $operation): bool
    {
        if (!in_array($operation, ['anonymize', 'delete'], true)) { throw new \InvalidArgumentException('Invalid maintenance operation.'); }
        if (!(($this->authorize)($actor, $form, 'formstudio.submissions.' . $operation))) { throw new \DomainException('Submission maintenance denied.'); }
        return $this->db->transaction(function () use ($form, $submission, $actor, $operation): bool {
            $row = $this->db->row('SELECT uuid, anonymized_at FROM ' . $this->db->table('submissions') . ' WHERE id = :id AND form_id = :form FOR UPDATE', [':id' => $submission, ':form' => $form]);
            if (!$row || ($operation === 'anonymize' && $row['anonymized_at'] !== null)) { return false; }
            // An interrupted action cannot retain personal data forever. Fence its
            // completion after the declared lease, before removing action rows.
            $this->db->execute('UPDATE ' . $this->db->table('action_runs') . " SET state = 'unknown', result_code = 'worker_interrupted', finished_at = :now, lease_token = NULL, lease_until = NULL, revision = revision + 1 WHERE submission_id = :id AND state = 'running' AND lease_until <= :expired", [':now' => gmdate('Y-m-d H:i:s'), ':expired' => gmdate('Y-m-d H:i:s'), ':id' => $submission]);
            $running = $this->db->row('SELECT id FROM ' . $this->db->table('action_runs') . " WHERE submission_id = :id AND state = 'running' LIMIT 1", [':id' => $submission]);
            if ($running) { throw new \DomainException('Submission actions are still running.'); }
            // A completed or in-flight export may already contain the erased data.
            // Revoke its download and lease in the same privacy transaction.
            $revoked = $this->db->execute('UPDATE ' . $this->db->table('jobs') . " SET state = 'cancelled', lease_token = NULL, lease_until = NULL, finished_at = :finished, expires_at = :expires, revision = revision + 1 WHERE form_id = :form AND job_type IN ('export-csv', 'export-json') AND state IN ('pending', 'running', 'retryable', 'completed')", [':finished' => gmdate('Y-m-d H:i:s'), ':expires' => gmdate('Y-m-d H:i:s'), ':form' => $form]);
            if ($revoked > 0) { $this->jobs->enqueue('export-cleanup', [], $actor); }
            $files = $this->db->rows('SELECT provider, storage_key FROM ' . $this->db->table('submission_files') . ' WHERE submission_id = :id', [':id' => $submission]);
            // The same transaction removes download authority and durably queues
            // opaque object cleanup, avoiding orphaned files on process failure.
            if ($files !== []) { $this->jobs->enqueue('file-cleanup', ['objects' => $files], $actor); }
            foreach (['submission_index', 'submission_files', 'submission_notes', 'action_runs'] as $table) { $this->db->execute('DELETE FROM ' . $this->db->table($table) . ' WHERE submission_id = :id', [':id' => $submission]); }
            $this->db->execute('UPDATE ' . $this->db->table('attempts') . " SET state = 'removed', request_hash = :hash, response = NULL, submission_uuid = NULL WHERE form_id = :form AND submission_uuid = :uuid", [':hash' => hash('sha256', random_bytes(32)), ':form' => $form, ':uuid' => $row['uuid']]);
            if ($operation === 'delete') { $this->db->execute('DELETE FROM ' . $this->db->table('submissions') . ' WHERE id = :id', [':id' => $submission]); }
            else {
                $this->db->execute('UPDATE ' . $this->db->table('submissions') . " SET canonical_payload = :payload, user_id = NULL, anonymized_at = :now, expires_at = NULL, action_status = 'anonymized', index_pending = 0 WHERE id = :id", [':payload' => CanonicalJson::encode(['schema_version' => '1.0', 'values' => [], 'consents' => [], 'option_labels' => []]), ':now' => gmdate('Y-m-d H:i:s'), ':id' => $submission]);
            }
            $this->db->insert('audit_log', ['correlation_id' => Uuid::create(), 'actor_id' => $actor, 'event_type' => 'submission.' . $operation, 'form_id' => $form, 'submission_uuid' => $row['uuid'], 'created_at' => gmdate('Y-m-d H:i:s'), 'safe_metadata' => CanonicalJson::encode(['files_queued' => count($files)])]);
            return true;
        });
    }
}
