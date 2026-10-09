<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Infrastructure\Database;

use Nicode\FormStudio\Actions\ActionLease;

final readonly class ActionRunRepository
{
    public function __construct(private Connection $db) {}
    public function assertContext(int $submission, \Nicode\FormStudio\Actions\ActionContext $context): void
    {
        $row = $this->db->row('SELECT s.uuid, v.hash FROM ' . $this->db->table('submissions') . ' s JOIN ' . $this->db->table('form_versions') . ' v ON v.id = s.form_version_id WHERE s.id = :id AND s.anonymized_at IS NULL', [':id' => $submission]);
        if (!$row || $row['uuid'] !== $context->reference || !hash_equals($row['hash'], $context->spec->hash)) { throw new \DomainException('Action context does not match submission snapshot.'); }
    }
    public function claim(int $submission, string $action, string $type, bool $retry = false, ?int $expectedAttempt = null): ?ActionLease
    {
        return $this->db->transaction(function () use ($submission, $action, $type, $retry, $expectedAttempt): ?ActionLease {
            $owner = $this->db->row('SELECT form_id FROM ' . $this->db->table('submissions') . ' WHERE id = :id', [':id' => $submission]);
            if (!$owner) { throw new \OutOfBoundsException('Submission not found.'); }
            $form = $this->db->row('SELECT state FROM ' . $this->db->table('forms') . ' WHERE id = :id FOR UPDATE', [':id' => (int) $owner['form_id']]);
            if (!$form || $form['state'] === 'deleting') { return null; }
            // Serialize claims briefly; release transaction before any external operation.
            $row = $this->db->row('SELECT id, anonymized_at FROM ' . $this->db->table('submissions') . ' WHERE id = :id FOR UPDATE', [':id' => $submission]);
            if (!$row) { throw new \OutOfBoundsException('Submission not found.'); }
            if ($row['anonymized_at'] !== null) { throw new \DomainException('Anonymized responses cannot execute actions.'); }
            $last = $this->latest($submission, $action);
            if ($expectedAttempt !== null && ($last === null || (int) $last['attempt'] !== $expectedAttempt)) { return null; }
            if ($last !== null && $last['state'] === 'running' && $last['lease_until'] <= gmdate('Y-m-d H:i:s')) {
                $this->finish(new ActionLease((int) $last['id'], $last['lease_token'], (int) $last['attempt']), 'unknown', 'worker_interrupted');
                return null;
            }
            if ($last !== null && (!$retry || $last['state'] !== 'failed')) { return null; }
            $attempt = (int) ($last['attempt'] ?? 0) + 1; $token = bin2hex(random_bytes(32)); $now = gmdate('Y-m-d H:i:s');
            $id = $this->db->insert('action_runs', ['submission_id' => $submission, 'action_uuid' => $action, 'action_type' => $type, 'attempt' => $attempt, 'state' => 'running', 'created_at' => $now, 'started_at' => $now, 'finished_at' => null, 'result_code' => null, 'next_retry_at' => null, 'lease_token' => $token, 'lease_until' => gmdate('Y-m-d H:i:s', time() + 300), 'revision' => 1]);
            return new ActionLease($id, $token, $attempt);
        });
    }
    public function latest(int $submission, string $action): ?array
    {
        return $this->db->row('SELECT * FROM ' . $this->db->table('action_runs') . ' WHERE submission_id = :submission AND action_uuid = :action ORDER BY attempt DESC LIMIT 1', [':submission' => $submission, ':action' => $action]);
    }
    public function finish(ActionLease $lease, string $state, string $code): void
    {
        if (!in_array($state, ['succeeded', 'failed', 'unknown', 'skipped'], true) || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $code) !== 1) { throw new \InvalidArgumentException('Invalid action result state/code.'); }
        $count = $this->db->execute('UPDATE ' . $this->db->table('action_runs') . ' SET state = :state, result_code = :code, finished_at = :now, lease_token = NULL, lease_until = NULL, revision = revision + 1 WHERE id = :id AND lease_token = :token AND state = :running', [':state' => $state, ':code' => $code, ':now' => gmdate('Y-m-d H:i:s'), ':id' => $lease->id, ':token' => $lease->token, ':running' => 'running']);
        if ($count !== 1) { throw new \DomainException('Action ownership lost.'); }
    }
    public function summarize(int $submission, string $status): void
    {
        if (!in_array($status, ['succeeded', 'partial_failure', 'blocking_failure', 'pending'], true)) { throw new \InvalidArgumentException('Invalid action summary.'); }
        $this->db->execute('UPDATE ' . $this->db->table('submissions') . ' SET action_status = :status, processed_at = :now WHERE id = :id AND anonymized_at IS NULL', [':status' => $status, ':now' => gmdate('Y-m-d H:i:s'), ':id' => $submission]);
    }
}
