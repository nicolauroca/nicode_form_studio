<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Jobs;

use Nicode\FormStudio\Contract\JobHandlerInterface;
use Nicode\FormStudio\Export\ExportWorkspace;
use Nicode\FormStudio\Infrastructure\Database\Connection;
use Nicode\FormStudio\Infrastructure\Database\JobRepository;

final readonly class ExportCleanupHandler implements JobHandlerInterface
{
    public function __construct(private Connection $db, private JobRepository $jobs, private ExportWorkspace $workspace) {}
    public function id(): string { return 'export-cleanup'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['resumable' => true, 'idempotent' => true, 'internal_only' => true]; }
    public function validateConfiguration(array $configuration, string $path): array { return []; }
    public function run(JobLease $job, int $limit): JobProgress
    {
        if ($limit < 1 || $limit > 500) { throw new \InvalidArgumentException('Invalid cleanup chunk.'); }
        $last = (int) ($job->cursor['last_id'] ?? 0); $now = $job->cursor['cutoff'] ?? gmdate('Y-m-d H:i:s');
        $stale = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') - 86400);
        $rows = $this->db->rows('SELECT id, uuid, job_type FROM ' . $this->db->table('jobs') . " WHERE job_type IN ('export-csv', 'export-json') AND id > :last AND (result_code IS NULL OR result_code <> 'artifact_expired') AND ((state IN ('completed', 'failed', 'cancelled') AND expires_at <= :now) OR (state IN ('failed', 'cancelled') AND finished_at <= :stale)) ORDER BY id LIMIT " . $limit, [':last' => $last, ':now' => $now, ':stale' => $stale]);
        foreach ($rows as $row) {
            $this->jobs->renew($job); $this->workspace->delete($row['uuid'], $row['job_type'] === 'export-json' ? 'json' : 'csv');
            $this->db->execute('UPDATE ' . $this->db->table('jobs') . " SET artifact_key = NULL, result_code = 'artifact_expired' WHERE id = :id", [':id' => (int) $row['id']]);
            $last = (int) $row['id'];
        }
        return new JobProgress(['last_id' => $last, 'cutoff' => $now], count($rows), complete: count($rows) < $limit);
    }
}
