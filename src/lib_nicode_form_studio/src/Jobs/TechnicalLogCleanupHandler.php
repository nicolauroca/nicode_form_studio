<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Jobs;

use Nicode\FormStudio\Contract\TransactionalJobHandlerInterface;
use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Infrastructure\Database\Connection;

/** Delete only expired technical events; audit records have an independent policy. */
final readonly class TechnicalLogCleanupHandler implements TransactionalJobHandlerInterface
{
    public function __construct(private Connection $db) {}
    public function id(): string { return 'technical-log-cleanup'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['resumable' => true, 'internal_only' => true]; }
    public function validateConfiguration(array $configuration, string $path): array
    {
        return is_int($configuration['days'] ?? null) && $configuration['days'] >= 1 && $configuration['days'] <= 3650 ? [] : [new Diagnostic('job.log_retention', $path, 'Expected 1–3650 retention days.')];
    }
    public function run(JobLease $job, int $limit): JobProgress
    {
        if ($this->validateConfiguration($job->parameters, '/job') !== [] || $limit < 1 || $limit > 500) { throw new \InvalidArgumentException('Invalid log cleanup.'); }
        $cutoff = $job->cursor['cutoff'] ?? gmdate('Y-m-d H:i:s', time() - $job->parameters['days'] * 86400);
        $parameters = [':cutoff' => $cutoff]; $after = '';
        if (isset($job->cursor['date'], $job->cursor['id'])) {
            $after = ' AND (created_at > :date OR (created_at = :same_date AND id > :id))';
            $parameters += [':date' => $job->cursor['date'], ':same_date' => $job->cursor['date'], ':id' => (int) $job->cursor['id']];
        }
        $rows = $this->db->rows('SELECT id, created_at FROM ' . $this->db->table('technical_log') . ' WHERE created_at < :cutoff' . $after . ' ORDER BY created_at, id LIMIT ' . $limit, $parameters);
        $cursor = $job->cursor + ['cutoff' => $cutoff];
        foreach ($rows as $row) {
            $this->db->execute('DELETE FROM ' . $this->db->table('technical_log') . ' WHERE id = :id', [':id' => (int) $row['id']]);
            $cursor['id'] = (int) $row['id']; $cursor['date'] = $row['created_at'];
        }
        return new JobProgress($cursor, count($rows), complete: count($rows) < $limit);
    }
}
