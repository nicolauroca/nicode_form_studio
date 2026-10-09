<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Jobs;

use Nicode\FormStudio\Application\FormDeletion;
use Nicode\FormStudio\Contract\TransactionalJobHandlerInterface;
use Nicode\FormStudio\Domain\{CanonicalJson, Diagnostic, Uuid};
use Nicode\FormStudio\Infrastructure\Database\{Connection, JobRepository, SubmissionMaintenance};

/** Each bounded destructive chunk is committed with its worker checkpoint. */
final readonly class FormDeleteHandler implements TransactionalJobHandlerInterface
{
    private const TABLES = ['rule_effects', 'rule_conditions', 'rules', 'actions', 'field_options', 'fields', 'elements', 'translations', 'version_field_policy', 'attempts', 'saved_views', 'form_versions'];
    public function __construct(private Connection $db, private JobRepository $jobs, private SubmissionMaintenance $privacy, private FormDeletion $deletion, private \Closure $removeAsset) {}
    public function id(): string { return 'form-delete'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['resumable' => true, 'transactional' => true, 'internal_only' => true, 'cancellable' => false]; }
    public function validateConfiguration(array $configuration, string $path): array
    {
        return array_keys($configuration) === ['form_id'] && is_int($configuration['form_id']) && $configuration['form_id'] > 0 ? [] : [new Diagnostic('job.form_delete', $path, 'A form ID is required.')];
    }
    public function run(JobLease $job, int $limit): JobProgress
    {
        if ($this->validateConfiguration($job->parameters, '/job') !== [] || $limit < 1 || $limit > 500) { throw new \InvalidArgumentException('Invalid form deletion chunk.'); }
        $form = $job->parameters['form_id'];
        $this->deletion->assert($form, $job->creator);
        (new \Nicode\FormStudio\Infrastructure\Database\PurgeState($this->db))->shareSchema();
        $row = $this->db->row('SELECT state FROM ' . $this->db->table('forms') . ' WHERE id = :id FOR UPDATE', [':id' => $form]);
        if (!$row || $row['state'] !== 'deleting') { throw new \DomainException('Form is not queued for deletion.'); }
        $this->jobs->renew($job);
        $staged = (new \Nicode\FormStudio\Infrastructure\Database\UploadJournal($this->db, $this->jobs))->reap($limit, $form);
        if ($staged > 0) { return new JobProgress($job->cursor, $staged); }
        if ($this->db->row('SELECT id FROM ' . $this->db->table('upload_staging') . ' WHERE form_id = :form LIMIT 1', [':form' => $form])) { throw new RetryableJobFailure('form_uploads_pending', 5); }
        $responses = $this->db->rows('SELECT id FROM ' . $this->db->table('submissions') . ' WHERE form_id = :form ORDER BY id LIMIT ' . $limit, [':form' => $form]);
        if ($responses !== []) {
            foreach ($responses as $response) {
                $this->jobs->renew($job);
                // Do not convert a temporary in-flight action into a terminal failure.
                $running = $this->db->row('SELECT id FROM ' . $this->db->table('action_runs') . " WHERE submission_id = :id AND state = 'running' AND (lease_until IS NULL OR lease_until > :now) LIMIT 1", [':id' => (int) $response['id'], ':now' => gmdate('Y-m-d H:i:s')]);
                if ($running) { throw new RetryableJobFailure('form_actions_running', 30); }
                $privacy = $this->privacy;
                if ((new \Nicode\FormStudio\Infrastructure\Database\PurgeState($this->db))->active()) {
                    $privacy = new SubmissionMaintenance($this->db, $this->jobs, function (int $actor, int $owner): bool { $this->deletion->assert($owner, $actor); return true; });
                }
                $privacy->apply($form, (int) $response['id'], $job->creator, 'delete');
            }
            return new JobProgress($job->cursor, count($responses));
        }
        $stage = (int) ($job->cursor['stage'] ?? 0);
        if ($stage < 0 || $stage > count(self::TABLES)) { throw new \DomainException('Invalid form deletion cursor.'); }
        if ($stage < count(self::TABLES)) {
            $table = self::TABLES[$stage];
            $rows = $this->db->rows('SELECT id FROM ' . $this->db->table($table) . ' WHERE form_id = :form ORDER BY id LIMIT ' . $limit, [':form' => $form]);
            if ($rows !== []) {
                $parameters = [':form' => $form]; $ids = [];
                foreach ($rows as $index => $entry) { $ids[] = ':id' . $index; $parameters[':id' . $index] = (int) $entry['id']; }
                $this->db->execute('DELETE FROM ' . $this->db->table($table) . ' WHERE form_id = :form AND id IN (' . implode(', ', $ids) . ')', $parameters);
            }
            return new JobProgress(['stage' => count($rows) < $limit ? $stage + 1 : $stage], count($rows));
        }
        ($this->removeAsset)($form);
        $this->db->execute('DELETE FROM ' . $this->db->table('forms') . " WHERE id = :id AND state = 'deleting'", [':id' => $form]);
        $this->db->insert('audit_log', ['correlation_id' => Uuid::create(), 'actor_id' => $job->creator, 'event_type' => 'form.delete', 'form_id' => $form, 'submission_uuid' => null, 'created_at' => gmdate('Y-m-d H:i:s'), 'safe_metadata' => CanonicalJson::encode(['job_id' => $job->id])]);
        return new JobProgress(['stage' => $stage], 1, complete: true);
    }
}
