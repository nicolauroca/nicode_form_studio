<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Infrastructure\Database;

use Nicode\FormStudio\Domain\CanonicalJson;
use Nicode\FormStudio\Domain\Uuid;
use Nicode\FormStudio\Jobs\JobLease;
use Nicode\FormStudio\Jobs\JobProgress;
use Nicode\FormStudio\Jobs\LeaseLost;

final readonly class JobRepository
{
    private \Closure $clock;
    public function __construct(private Connection $db, ?\Closure $clock = null) { $this->clock = $clock ?? time(...); }

    public function enqueue(string $type, array $parameters, int $creator, ?int $availableAt = null): int
    {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/D', $type) !== 1) { throw new \InvalidArgumentException('Invalid job type.'); }
        return $this->db->insert('jobs', ['uuid' => Uuid::create(), 'job_type' => $type, 'form_id' => is_int($parameters['form_id'] ?? null) ? $parameters['form_id'] : null, 'creator_id' => $creator, 'state' => 'pending', 'parameters' => CanonicalJson::encode($parameters), 'cursor_data' => '[]', 'processed' => 0, 'failed' => 0, 'created_at' => $this->date(), 'started_at' => null, 'finished_at' => null, 'available_at' => $this->date($availableAt), 'lease_token' => null, 'lease_until' => null, 'revision' => 0, 'result_code' => null, 'artifact_key' => null, 'expires_at' => null]);
    }

    public function claim(int $leaseSeconds = 60): ?JobLease
    {
        if ($leaseSeconds < 10 || $leaseSeconds > 3600) { throw new \InvalidArgumentException('Invalid lease duration.'); }
        return $this->db->transaction(function () use ($leaseSeconds): ?JobLease {
            $row = $this->db->row('SELECT * FROM ' . $this->db->table('jobs') . " WHERE ((state IN ('pending', 'retryable') AND available_at <= :available) OR (state = 'running' AND lease_until <= :expired)) ORDER BY available_at, id LIMIT 1 FOR UPDATE SKIP LOCKED", [':available' => $this->date(), ':expired' => $this->date()]);
            if ($row === null) { return null; }
            $token = bin2hex(random_bytes(32)); $revision = (int) $row['revision'] + 1;
            $this->db->execute('UPDATE ' . $this->db->table('jobs') . " SET state = 'running', lease_token = :token, lease_until = :until, started_at = COALESCE(started_at, :started), revision = :revision WHERE id = :id", [':token' => $token, ':until' => $this->date(($this->clock)() + $leaseSeconds), ':started' => $this->date(), ':revision' => $revision, ':id' => (int) $row['id']]);
            return new JobLease((int) $row['id'], $row['uuid'], $row['job_type'], (int) $row['creator_id'], json_decode($row['parameters'], true, 512, JSON_THROW_ON_ERROR), json_decode($row['cursor_data'], true, 512, JSON_THROW_ON_ERROR), $token, $revision, (int) $row['processed'], (int) $row['failed']);
        });
    }

    public function checkpoint(JobLease $lease, JobProgress $progress): void
    {
        $changed = $this->db->execute('UPDATE ' . $this->db->table('jobs') . ' SET state = :state, cursor_data = :cursor, processed = processed + :processed, failed = failed + :failed, finished_at = :finished, artifact_key = :artifact, expires_at = :expires, lease_token = NULL, lease_until = NULL, revision = revision + 1 WHERE id = :id AND lease_token = :token AND revision = :revision AND state = :running AND lease_until > :now', [
            ':state' => $progress->complete ? 'completed' : 'pending', ':cursor' => CanonicalJson::encode($progress->cursor), ':processed' => $progress->processed, ':failed' => $progress->failed, ':finished' => $progress->complete ? $this->date() : null, ':artifact' => $progress->artifactKey,
            ':expires' => $progress->complete && $progress->artifactKey !== null ? $this->date(($this->clock)() + 86400) : null,
            ':id' => $lease->id, ':token' => $lease->token, ':revision' => $lease->revision, ':running' => 'running', ':now' => $this->date(),
        ]);
        if ($changed !== 1) { throw new LeaseLost(); }
    }

    public function fail(JobLease $lease, string $code, ?int $retryAt = null): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $code) !== 1) { throw new \InvalidArgumentException('Only safe result codes may be stored.'); }
        $changed = $this->db->execute('UPDATE ' . $this->db->table('jobs') . ' SET state = :state, result_code = :code, available_at = :available, finished_at = :finished, lease_token = NULL, lease_until = NULL, revision = revision + 1 WHERE id = :id AND lease_token = :token AND revision = :revision AND state = :running AND lease_until > :now', [
            ':state' => $retryAt === null ? 'failed' : 'retryable', ':code' => $code, ':available' => $this->date($retryAt), ':finished' => $retryAt === null ? $this->date() : null,
            ':id' => $lease->id, ':token' => $lease->token, ':revision' => $lease->revision, ':running' => 'running', ':now' => $this->date(),
        ]);
        if ($changed !== 1) { throw new LeaseLost(); }
    }

    public function renew(JobLease $lease, int $seconds = 60): void
    {
        if ($seconds < 10 || $seconds > 3600) { throw new \InvalidArgumentException('Invalid lease duration.'); }
        $changed = $this->db->execute('UPDATE ' . $this->db->table('jobs') . ' SET lease_until = :until WHERE id = :id AND lease_token = :token AND revision = :revision AND state = :running AND lease_until > :now', [':until' => $this->date(($this->clock)() + $seconds), ':id' => $lease->id, ':token' => $lease->token, ':revision' => $lease->revision, ':running' => 'running', ':now' => $this->date()]);
        if ($changed !== 1) {
            // MySQL reports zero changed rows when renewing within the same second.
            $owned = $this->db->row('SELECT id FROM ' . $this->db->table('jobs') . ' WHERE id = :id AND lease_token = :token AND revision = :revision AND state = :running AND lease_until > :now', [':id' => $lease->id, ':token' => $lease->token, ':revision' => $lease->revision, ':running' => 'running', ':now' => $this->date()]);
            if ($owned === null) { throw new LeaseLost(); }
        }
    }

    public function retry(JobLease $lease, string $code, int $delaySeconds): void
    {
        if ($delaySeconds < 1 || $delaySeconds > 86400) { throw new \InvalidArgumentException('Invalid retry delay.'); }
        $this->fail($lease, $code, ($this->clock)() + $delaySeconds);
    }

    public function cancel(int $id): bool
    {
        return $this->db->execute('UPDATE ' . $this->db->table('jobs') . " SET state = 'cancelled', lease_token = NULL, lease_until = NULL, finished_at = :now, revision = revision + 1 WHERE id = :id AND state IN ('pending', 'running', 'retryable')", [':id' => $id, ':now' => $this->date()]) === 1;
    }
    public function get(int $id): array
    {
        return $this->db->row('SELECT * FROM ' . $this->db->table('jobs') . ' WHERE id = :id', [':id' => $id]) ?? throw new \OutOfBoundsException('Job not found.');
    }
    private function date(?int $timestamp = null): string { return gmdate('Y-m-d H:i:s', $timestamp ?? ($this->clock)()); }
}
