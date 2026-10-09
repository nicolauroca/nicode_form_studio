<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Jobs;

use Nicode\FormStudio\Application\{FormDeletion, PackagePurge};
use Nicode\FormStudio\Contract\TransactionalJobHandlerInterface;
use Nicode\FormStudio\Domain\{CanonicalJson, Diagnostic};
use Nicode\FormStudio\Infrastructure\Database\{Connection, JobRepository, PurgeState};

final readonly class PackagePurgeHandler implements TransactionalJobHandlerInterface
{
    public function __construct(private Connection $db, private JobRepository $jobs, private PurgeState $state, private PackagePurge $purge, private FormDeletion $deletion) {}
    public function id(): string { return 'package-purge'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['resumable' => true, 'transactional' => true, 'internal_only' => true, 'cancellable' => false]; }
    public function validateConfiguration(array $configuration, string $path): array { return $configuration === [] ? [] : [new Diagnostic('job.purge', $path, 'Purge has no client-supplied parameters.')]; }
    public function run(JobLease $job, int $limit): JobProgress
    {
        if ($limit < 1 || $limit > 500) { throw new \InvalidArgumentException('Invalid purge chunk.'); }
        $this->purge->assert($job->creator); $this->state->lockSchema(); $state = $this->state->read();
        if (($state['job_id'] ?? null) !== $job->id || ($state['phase'] ?? null) !== 'preparing') { throw new \DomainException('Purge ownership changed.'); }
        $this->jobs->renew($job);
        $staged = (new \Nicode\FormStudio\Infrastructure\Database\UploadJournal($this->db, $this->jobs))->reap($limit, purge: true);
        if ($staged > 0) { return new JobProgress([], $staged); }
        if ($this->db->row('SELECT id FROM ' . $this->db->table('upload_staging') . ' LIMIT 1 FOR UPDATE')) { throw new RetryableJobFailure('purge_uploads_pending', 5); }
        $forms = $this->db->rows('SELECT f.id FROM ' . $this->db->table('forms') . ' f WHERE NOT EXISTS (SELECT 1 FROM ' . $this->db->table('jobs') . " j WHERE j.form_id = f.id AND j.job_type = 'form-delete' AND j.state <> 'completed') ORDER BY f.id LIMIT " . min($limit, 50));
        foreach ($forms as $form) { $this->jobs->renew($job); $this->deletion->enqueueForPurge((int) $form['id'], $job->creator); }
        if ($forms !== []) { return new JobProgress([], count($forms)); }
        if ($this->db->row('SELECT id FROM ' . $this->db->table('forms') . ' LIMIT 1')) { throw new RetryableJobFailure('purge_forms_pending', 5); }
        if ($this->db->row('SELECT id FROM ' . $this->db->table('jobs') . " WHERE job_type IN ('file-cleanup', 'export-cleanup') AND state <> 'completed' LIMIT 1")
            || $this->db->row('SELECT id FROM ' . $this->db->table('jobs') . " WHERE job_type IN ('export-csv', 'export-json') AND (result_code IS NULL OR result_code <> 'artifact_expired') LIMIT 1")) { throw new RetryableJobFailure('purge_cleanup_pending', 10); }
        if ($this->db->row('SELECT id FROM ' . $this->db->table('submissions') . ' LIMIT 1') || $this->db->row('SELECT id FROM ' . $this->db->table('submission_files') . ' LIMIT 1')) { throw new \DomainException('Purge left unowned response records.'); }
        $state['phase'] = 'ready'; $state['ready_at'] = gmdate('Y-m-d H:i:s');
        $this->db->execute('UPDATE ' . $this->db->table('installation_state') . ' SET state_json = :state WHERE state_key = :key', [':state' => CanonicalJson::encode($state), ':key' => 'purge']);
        return new JobProgress([], 0, complete: true);
    }
}
