<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Domain\{CanonicalJson, ConcurrentEdit, Uuid};
use Nicode\FormStudio\Infrastructure\Database\{Connection, JobRepository, PurgeState};

final readonly class PackagePurge
{
    public function __construct(private Connection $db, private JobRepository $jobs, private PurgeState $state, private \Closure $authorize, private string $confirmationKey)
    {
        if (strlen($confirmationKey) < 32) { throw new \InvalidArgumentException('Purge confirmation key is too short.'); }
    }
    public function review(int $actor): array
    {
        $this->assert($actor); $counts = [];
        foreach (['forms', 'submissions', 'submission_files', 'upload_staging'] as $table) { $counts[$table] = (int) $this->db->row('SELECT COUNT(*) AS total FROM ' . $this->db->table($table))['total']; }
        $counts['bytes'] = (int) $this->db->row('SELECT COALESCE(SUM(size_bytes), 0) AS total FROM ' . $this->db->table('submission_files'))['total'];
        $state = $this->state->read();
        return $counts + ['state' => $state['phase'] ?? 'preserve', 'job_id' => $state['job_id'] ?? null, 'confirmation' => hash_hmac('sha256', CanonicalJson::encode([$actor, $counts, $state]), $this->confirmationKey)];
    }
    public function prepare(int $actor, string $confirmation, string $phrase): int
    {
        $this->assert($actor);
        return $this->db->transaction(function () use ($actor, $confirmation, $phrase): int {
            $this->state->lockSchema(); $review = $this->review($actor);
            if ($phrase !== 'DELETE FORMSTUDIO DATA' || !hash_equals($review['confirmation'], $confirmation)) { throw new ConcurrentEdit('Purge review changed or confirmation is missing.'); }
            $previous = $this->state->read();
            if (($previous['phase'] ?? '') === 'ready') { return (int) $previous['job_id']; }
            if (isset($previous['job_id']) && in_array($this->jobs->get((int) $previous['job_id'])['state'], ['pending', 'running', 'retryable'], true)) {
                $job = (int) $previous['job_id'];
            } else { $job = $this->jobs->enqueue('package-purge', [], $actor); }
            $state = CanonicalJson::encode(['phase' => 'preparing', 'job_id' => $job, 'actor_id' => $actor, 'requested_at' => $previous['requested_at'] ?? gmdate('Y-m-d H:i:s'), 'schema' => '1.0.0']);
            if ($previous === null) { $this->db->insert('installation_state', ['state_key' => 'purge', 'state_json' => $state]); }
            else { $this->db->execute('UPDATE ' . $this->db->table('installation_state') . ' SET state_json = :state WHERE state_key = :key', [':state' => $state, ':key' => 'purge']); }
            $now = gmdate('Y-m-d H:i:s');
            $this->db->execute('UPDATE ' . $this->db->table('jobs') . " SET state = 'cancelled', lease_token = NULL, lease_until = NULL, finished_at = :now, expires_at = :expires, revision = revision + 1 WHERE job_type NOT IN ('package-purge', 'form-delete', 'file-cleanup', 'export-cleanup') AND (state IN ('pending', 'running', 'retryable') OR job_type IN ('export-csv', 'export-json'))", [':now' => $now, ':expires' => $now]);
            $this->db->execute('UPDATE ' . $this->db->table('jobs') . " SET state = 'pending', available_at = :now, finished_at = NULL, result_code = NULL, revision = revision + 1 WHERE job_type IN ('file-cleanup', 'export-cleanup') AND state IN ('failed', 'cancelled')", [':now' => $now]);
            $this->db->execute('UPDATE ' . $this->db->table('jobs') . " SET state = 'pending', creator_id = :actor, available_at = :now, finished_at = NULL, result_code = NULL, revision = revision + 1 WHERE job_type = 'form-delete' AND state IN ('failed', 'cancelled')", [':actor' => $actor, ':now' => $now]);
            if ($this->db->row('SELECT id FROM ' . $this->db->table('jobs') . " WHERE job_type IN ('export-csv', 'export-json') AND (result_code IS NULL OR result_code <> 'artifact_expired') LIMIT 1")) { $this->jobs->enqueue('export-cleanup', [], $actor); }
            $this->db->insert('audit_log', ['correlation_id' => Uuid::create(), 'actor_id' => $actor, 'event_type' => 'package.purge_requested', 'form_id' => null, 'submission_uuid' => null, 'created_at' => $now, 'safe_metadata' => CanonicalJson::encode(['job_id' => $job])]);
            return $job;
        });
    }
    public function assert(int $actor): void
    {
        if ($actor < 1 || !($this->authorize)($actor, null, 'core.manage') || !($this->authorize)($actor, null, 'core.admin')) { throw new \DomainException('Package purge requires component administration.'); }
    }
}
