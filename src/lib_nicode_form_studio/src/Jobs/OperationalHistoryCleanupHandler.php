<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Jobs;

use Nicode\FormStudio\Contract\TransactionalJobHandlerInterface;
use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Infrastructure\Database\Connection;

/** Retire historical records without discarding Action idempotency markers. */
final readonly class OperationalHistoryCleanupHandler implements TransactionalJobHandlerInterface
{
    public function __construct(private Connection $db, private string $kind, private \Closure $currentDays)
    {
        if (!in_array($kind, ['audit', 'action'], true)) { throw new \InvalidArgumentException('Invalid history kind.'); }
    }
    public function id(): string { return $this->kind . '-history-cleanup'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['resumable' => true, 'internal_only' => true]; }
    public function validateConfiguration(array $configuration, string $path): array
    {
        return array_keys($configuration) === ['days'] && is_int($configuration['days']) && $configuration['days'] >= 1 && $configuration['days'] <= 3650 ? [] : [new Diagnostic('job.history_retention', $path, 'Expected 1–3650 retention days.')];
    }
    public function run(JobLease $job, int $limit): JobProgress
    {
        if (!$this->db->inTransaction() || $this->validateConfiguration($job->parameters, '/job') !== [] || $limit < 1 || $limit > 500) { throw new \InvalidArgumentException('Invalid transactional history cleanup.'); }
        if (($this->currentDays)() !== $job->parameters['days']) { return new JobProgress($job->cursor, 0, complete: true); }
        $cutoff = $job->cursor['cutoff'] ?? gmdate('Y-m-d H:i:s', time() - $job->parameters['days'] * 86400);
        $cursor = $job->cursor + ['cutoff' => $cutoff, 'deleted' => 0];
        $parameters = [':cutoff' => $cutoff]; $after = '';
        if (isset($cursor['date'], $cursor['id'])) {
            $after = ' AND (a.created_at > :date OR (a.created_at = :same_date AND a.id > :id))';
            $parameters += [':date' => $cursor['date'], ':same_date' => $cursor['date'], ':id' => (int) $cursor['id']];
        }
        $table = $this->db->table($this->kind === 'audit' ? 'audit_log' : 'action_runs');
        $extra = $this->kind === 'action' ? ', a.state, CASE WHEN EXISTS (SELECT 1 FROM ' . $table . ' newer WHERE newer.submission_id = a.submission_id AND newer.action_uuid = a.action_uuid AND newer.attempt > a.attempt) THEN 1 ELSE 0 END AS superseded' : '';
        $rows = $this->db->rows('SELECT a.id, a.created_at' . $extra . ' FROM ' . $table . ' a WHERE a.created_at < :cutoff' . $after . ' ORDER BY a.created_at, a.id LIMIT ' . $limit . ' FOR UPDATE', $parameters);
        foreach ($rows as $row) {
            if ($this->kind === 'audit' || (in_array($row['state'], ['succeeded', 'failed', 'unknown', 'skipped'], true) && (int) $row['superseded'] === 1)) {
                $this->db->execute('DELETE FROM ' . $table . ' WHERE id = :id', [':id' => (int) $row['id']]);
                $cursor['deleted']++;
            }
            $cursor['id'] = (int) $row['id']; $cursor['date'] = $row['created_at'];
        }
        return new JobProgress($cursor, count($rows), complete: count($rows) < $limit);
    }
}
