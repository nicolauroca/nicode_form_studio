<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;
use Nicode\FormStudio\Domain\{CanonicalJson, ConcurrentEdit, Uuid};
use Nicode\FormStudio\Infrastructure\Database\{ActionRunRepository, Connection, FormRepository, JobRepository, SubmissionRepository};
use Nicode\FormStudio\Registry\ActionRegistry;

final readonly class ActionRetries
{
    public function __construct(private Connection $db, private FormRepository $forms, private SubmissionRepository $submissions, private ActionRunRepository $runs, private JobRepository $jobs, private ActionRegistry $actions, private \Closure $authorize) {}
    public function eligible(int $actor, int $form, int $submission): array
    {
        foreach ([[null, 'core.manage'], [$form, 'formstudio.submissions.view'], [$form, 'formstudio.submissions.retry']] as [$scope, $permission]) {
            if (!($this->authorize)($actor, $scope, $permission)) { return []; }
        }
        $row = $this->submissions->get($form, $submission);
        if ($row['anonymized_at'] !== null) { return []; }
        $spec = $this->forms->version($form, (int) $row['form_version_id']); $result = [];
        foreach ($spec->toArray()['actions'] as $action) {
            if (!($action['enabled'] ?? true) || !$this->actions->has($action['type']) || ($this->actions->get($action['type'])->metadata()['retry'] ?? null) !== 'definite_failure_only') { continue; }
            $last = $this->runs->latest($submission, $action['uuid']);
            if ($last !== null && $last['state'] === 'failed') { $result[$action['uuid']] = (int) $last['attempt']; }
        }
        return $result;
    }
    public function enqueue(int $actor, int $form, int $submission, array $attempts): int
    {
        if ($attempts === [] || count($attempts) > 500) { throw new \InvalidArgumentException('Expected failed actions.'); }
        foreach ($attempts as $uuid => $attempt) { if (!Uuid::valid($uuid) || !is_int($attempt) || $attempt < 1) { throw new \InvalidArgumentException('Invalid expected action attempt.'); } }
        $eligible = $this->eligible($actor, $form, $submission);
        if ($eligible === []) { throw new \DomainException('No authorized compatible failures.'); }
        foreach ($attempts as $uuid => $attempt) { if (($eligible[$uuid] ?? null) !== $attempt) { throw new ConcurrentEdit('Action attempt changed.'); } }
        return $this->db->transaction(function () use ($actor, $form, $submission, $attempts): int {
            $owner = $this->db->row('SELECT state FROM ' . $this->db->table('forms') . ' WHERE id = :id FOR UPDATE', [':id' => $form]);
            if (!$owner || $owner['state'] === 'deleting') { throw new \DomainException('Form deletion is in progress.'); }
            $row = $this->submissions->get($form, $submission);
            $id = $this->jobs->enqueue('action-retry', ['form_id' => $form, 'submission_id' => $submission, 'attempts' => $attempts], $actor);
            $this->db->insert('audit_log', ['correlation_id' => Uuid::create(), 'actor_id' => $actor, 'event_type' => 'action.retry_requested', 'form_id' => $form, 'submission_uuid' => $row['uuid'], 'created_at' => gmdate('Y-m-d H:i:s'), 'safe_metadata' => CanonicalJson::encode(['job_id' => $id, 'attempts' => $attempts])]);
            return $id;
        });
    }
}
