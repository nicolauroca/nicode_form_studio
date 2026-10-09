<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Jobs;

use Nicode\FormStudio\Contract\TransactionalJobHandlerInterface;
use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Infrastructure\Database\{Connection, FormRepository, JobRepository, SubmissionMaintenance};

/** Expire technical replay data and abandoned explicit no-storage responses. */
final readonly class AttemptCleanupHandler implements TransactionalJobHandlerInterface
{
    public function __construct(private Connection $db, private FormRepository $forms, private JobRepository $jobs) {}
    public function id(): string { return 'attempt-cleanup'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['resumable' => true, 'internal_only' => true]; }
    public function validateConfiguration(array $configuration, string $path): array { return $configuration === [] ? [] : [new Diagnostic('job.attempt_cleanup', $path, 'No attempt cleanup parameters are accepted.')]; }
    public function run(JobLease $job, int $limit): JobProgress
    {
        if ($job->parameters !== [] || $limit < 1 || $limit > 500 || !$this->db->inTransaction()) { throw new \InvalidArgumentException('Invalid attempt cleanup context.'); }
        $cutoff = $job->cursor['cutoff'] ?? gmdate('Y-m-d H:i:s', time() - 1);
        $date = $job->cursor['expires_at'] ?? '1000-01-01 00:00:00'; $id = (int) ($job->cursor['id'] ?? 0);
        $rows = $this->db->rows('SELECT id, form_id, expires_at FROM ' . $this->db->table('attempts') . ' WHERE expires_at <= :cutoff AND (expires_at > :date OR (expires_at = :same AND id > :id)) ORDER BY expires_at, id LIMIT ' . $limit, [':cutoff' => $cutoff, ':date' => $date, ':same' => $date, ':id' => $id]);
        $processed = 0;
        foreach ($rows as $candidate) {
            $date = $candidate['expires_at']; $id = (int) $candidate['id'];
            // Match action claim lock order. The next hourly job revisits live actions.
            $form = $this->db->row('SELECT state FROM ' . $this->db->table('forms') . ' WHERE id=:id FOR UPDATE', [':id' => (int) $candidate['form_id']]);
            if ($form === null || $form['state'] === 'deleting') { continue; }
            $attempt = $this->db->row('SELECT * FROM ' . $this->db->table('attempts') . ' WHERE id=:id AND expires_at <= :cutoff', [':id' => $id, ':cutoff' => $cutoff]);
            if ($attempt === null) { continue; }
            $response = $this->db->row('SELECT id, form_version_id FROM ' . $this->db->table('submissions') . ' WHERE form_id=:form AND uuid=:uuid FOR UPDATE', [':form' => (int) $candidate['form_id'], ':uuid' => $attempt['submission_uuid']]);
            // Completion/privacy lock response before attempt; match that order.
            $attempt = $this->db->row('SELECT * FROM ' . $this->db->table('attempts') . ' WHERE id=:id AND expires_at <= :cutoff FOR UPDATE', [':id' => $id, ':cutoff' => $cutoff]);
            if ($attempt === null) { continue; }
            if ($response !== null) {
                $running = $this->db->row('SELECT id FROM ' . $this->db->table('action_runs') . " WHERE submission_id=:id AND state='running' AND (lease_until IS NULL OR lease_until > :now) LIMIT 1", [':id' => (int) $response['id'], ':now' => gmdate('Y-m-d H:i:s')]);
                if ($running !== null) { continue; }
                $spec = $this->forms->version((int) $candidate['form_id'], (int) $response['form_version_id']);
                if (($spec->toArray()['persistence']['mode'] ?? 'full') === 'none') {
                    // Authority is limited to owned, verified historical no-storage policy.
                    (new SubmissionMaintenance($this->db, $this->jobs, static fn (): bool => true))->apply((int) $candidate['form_id'], (int) $response['id'], 0, 'delete');
                }
            }
            $this->db->execute('DELETE FROM ' . $this->db->table('attempts') . ' WHERE id=:id', [':id' => $id]); $processed++;
        }
        return new JobProgress(['cutoff' => $cutoff, 'expires_at' => $date, 'id' => $id], $processed, complete: count($rows) < $limit);
    }
}
