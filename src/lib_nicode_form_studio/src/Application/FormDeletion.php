<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Domain\{CanonicalJson, ConcurrentEdit, Uuid};
use Nicode\FormStudio\Infrastructure\Database\{Connection, JobRepository};

/** Confirmation and authorization boundary; never accepts file keys or paths. */
final readonly class FormDeletion
{
    public function __construct(private Connection $db, private JobRepository $jobs, private \Closure $authorize) {}

    public function review(int $form, int $actor): array
    {
        $this->assert($form, $actor);
        $row = $this->db->row('SELECT id, uuid, name, state, draft_revision FROM ' . $this->db->table('forms') . ' WHERE id = :id', [':id' => $form]) ?? throw new \OutOfBoundsException('Form unavailable.');
        if (!in_array($row['state'], ['trashed', 'deleting'], true)) { throw new \DomainException('Move the form to trash first.'); }
        $responses = $this->db->row('SELECT COUNT(*) AS total FROM ' . $this->db->table('submissions') . ' WHERE form_id = :form', [':form' => $form]);
        $files = $this->db->row('SELECT COUNT(*) AS total, COALESCE(SUM(f.size_bytes), 0) AS bytes FROM ' . $this->db->table('submission_files') . ' f JOIN ' . $this->db->table('submissions') . ' s ON s.id = f.submission_id WHERE s.form_id = :form', [':form' => $form]);
        return $row + ['responses' => (int) $responses['total'], 'files' => (int) $files['total'], 'bytes' => (int) $files['bytes']];
    }

    public function enqueue(int $form, int $revision, int $actor, string $confirmation): int
    {
        $this->assert($form, $actor);
        return $this->db->transaction(function () use ($form, $revision, $actor, $confirmation): int {
            $row = $this->db->row('SELECT uuid, state, draft_revision FROM ' . $this->db->table('forms') . ' WHERE id = :id FOR UPDATE', [':id' => $form]) ?? throw new \OutOfBoundsException('Form unavailable.');
            if ((int) $row['draft_revision'] !== $revision) { throw new ConcurrentEdit(); }
            if (!in_array($row['state'], ['trashed', 'deleting'], true) || !hash_equals($row['uuid'], $confirmation)) { throw new \DomainException('Deletion confirmation does not match a trashed form.'); }
            $existing = $this->db->row('SELECT id FROM ' . $this->db->table('jobs') . " WHERE form_id = :form AND job_type = 'form-delete' AND state IN ('pending', 'running', 'retryable') ORDER BY id LIMIT 1", [':form' => $form]);
            if ($existing) { return (int) $existing['id']; }
            $this->db->execute('UPDATE ' . $this->db->table('forms') . " SET state = 'deleting', draft_revision = draft_revision + 1, modified_at = :now, modified_by = :actor WHERE id = :id", [':id' => $form, ':actor' => $actor, ':now' => gmdate('Y-m-d H:i:s')]);
            $this->db->execute('UPDATE ' . $this->db->table('jobs') . " SET state = 'cancelled', lease_token = NULL, lease_until = NULL, finished_at = :now, expires_at = :expires, revision = revision + 1 WHERE form_id = :form AND (state IN ('pending', 'running', 'retryable') OR job_type IN ('export-csv', 'export-json'))", [':form' => $form, ':now' => gmdate('Y-m-d H:i:s'), ':expires' => gmdate('Y-m-d H:i:s')]);
            if ($this->db->row('SELECT id FROM ' . $this->db->table('jobs') . " WHERE form_id = :form AND job_type IN ('export-csv', 'export-json') LIMIT 1", [':form' => $form])) { $this->jobs->enqueue('export-cleanup', [], $actor); }
            $job = $this->jobs->enqueue('form-delete', ['form_id' => $form], $actor);
            $this->db->insert('audit_log', ['correlation_id' => Uuid::create(), 'actor_id' => $actor, 'event_type' => 'form.delete_requested', 'form_id' => $form, 'submission_uuid' => null, 'created_at' => gmdate('Y-m-d H:i:s'), 'safe_metadata' => CanonicalJson::encode(['job_id' => $job, 'revision' => $revision + 1])]);
            return $job;
        });
    }

    public function assert(int $form, int $actor): void
    {
        if ($actor > 0 && ($this->authorize)($actor, null, 'core.manage') && ($this->authorize)($actor, null, 'core.admin') && (new \Nicode\FormStudio\Infrastructure\Database\PurgeState($this->db))->active()) { return; }
        foreach ([[null, 'core.manage'], [$form, 'formstudio.forms.manage'], [$form, 'core.delete'], [$form, 'formstudio.submissions.delete']] as [$scope, $permission]) {
            if ($actor < 1 || !($this->authorize)($actor, $scope, $permission)) { throw new \DomainException('Permanent form deletion denied.'); }
        }
    }

    /** Internal orchestrator path; the package-level confirmation already covers all forms. */
    public function enqueueForPurge(int $form, int $actor): int
    {
        if (!($this->authorize)($actor, null, 'core.admin') || !(new \Nicode\FormStudio\Infrastructure\Database\PurgeState($this->db))->active()) { throw new \DomainException('Package purge is not confirmed.'); }
        return $this->db->transaction(function () use ($form, $actor): int {
            $row = $this->db->row('SELECT uuid, state, draft_revision FROM ' . $this->db->table('forms') . ' WHERE id = :id FOR UPDATE', [':id' => $form]) ?? throw new \OutOfBoundsException('Form unavailable.');
            if ($row['state'] !== 'deleting') { $this->db->execute('UPDATE ' . $this->db->table('forms') . " SET state = 'trashed' WHERE id = :id", [':id' => $form]); }
            return $this->enqueue($form, (int) $row['draft_revision'], $actor, $row['uuid']);
        });
    }
}
