<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Jobs;

use Nicode\FormStudio\Contract\TransactionalJobHandlerInterface;
use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Infrastructure\Database\Connection;

/** Expired pseudonymous counters must not accumulate indefinitely. */
final readonly class RateLimitCleanupHandler implements TransactionalJobHandlerInterface
{
    public function __construct(private Connection $db) {}
    public function id(): string { return 'rate-limit-cleanup'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['resumable' => true, 'internal_only' => true]; }
    public function validateConfiguration(array $configuration, string $path): array { return $configuration === [] ? [] : [new Diagnostic('job.rate_cleanup', $path, 'No rate cleanup parameters are accepted.')]; }
    public function run(JobLease $job, int $limit): JobProgress
    {
        if ($job->parameters !== [] || $limit < 1 || $limit > 500 || !$this->db->inTransaction()) { throw new \InvalidArgumentException('Invalid rate cleanup context.'); }
        $cutoff = $job->cursor['cutoff'] ?? gmdate('Y-m-d H:i:s');
        $rows = $this->db->rows('SELECT id FROM ' . $this->db->table('rate_limits') . ' WHERE expires_at <= :cutoff ORDER BY expires_at LIMIT ' . $limit . ' FOR UPDATE SKIP LOCKED', [':cutoff' => $cutoff]);
        foreach ($rows as $row) { $this->db->execute('DELETE FROM ' . $this->db->table('rate_limits') . ' WHERE id = :id', [':id' => (int) $row['id']]); }
        return new JobProgress(['cutoff' => $cutoff], count($rows), complete: count($rows) < $limit);
    }
}
