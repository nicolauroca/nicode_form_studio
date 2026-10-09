<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Domain\{CanonicalJson, ConcurrentEdit, Uuid};
use Nicode\FormStudio\Infrastructure\Database\Connection;

final readonly class SubmissionAdministration
{
    public const STATES = ['new', 'viewed', 'reviewed', 'processed', 'error', 'archived', 'spam'];
    public function __construct(private Connection $db, private \Closure $authorize) {}

    public function changeState(int $actor, int $form, int $submission, string $expected, string $state): void
    {
        $this->assertAccess($actor, $form);
        if (!in_array($state, self::STATES, true) || !in_array($expected, self::STATES, true)) { throw new \InvalidArgumentException('Invalid response state.'); }
        $this->db->transaction(function () use ($actor, $form, $submission, $expected, $state): void {
            $row = $this->lock($form, $submission);
            if ($row['state'] !== $expected) { throw new ConcurrentEdit('Response state changed.'); }
            if ($state === $expected) { return; }
            $this->db->execute('UPDATE ' . $this->db->table('submissions') . ' SET state = :state WHERE id = :id AND form_id = :form', [':state' => $state, ':id' => $submission, ':form' => $form]);
            $this->audit($actor, $form, $row['uuid'], 'state', ['from' => $expected, 'to' => $state]);
        });
    }

    public function addNote(int $actor, int $form, int $submission, string $body): int
    {
        $this->assertAccess($actor, $form);
        $body = trim($body);
        if ($body === '' || strlen($body) > 16000 || !mb_check_encoding($body, 'UTF-8') || str_contains($body, "\0")) { throw new \InvalidArgumentException('Invalid note.'); }
        return $this->db->transaction(function () use ($actor, $form, $submission, $body): int {
            $row = $this->lock($form, $submission);
            if ($row['anonymized_at'] !== null) { throw new \DomainException('Anonymized response cannot receive notes.'); }
            $id = $this->db->insert('submission_notes', ['submission_id' => $submission, 'created_by' => $actor, 'created_at' => gmdate('Y-m-d H:i:s'), 'body' => $body]);
            $this->audit($actor, $form, $row['uuid'], 'note', ['note_id' => $id]);
            return $id;
        });
    }

    private function assertAccess(int $actor, int $form): void
    {
        foreach ([[null, 'core.manage'], [$form, 'formstudio.submissions.view'], [$form, 'formstudio.submissions.manage']] as [$scope, $permission]) {
            if (!(($this->authorize)($actor, $scope, $permission))) { throw new \DomainException('Response management denied.'); }
        }
    }
    private function lock(int $form, int $submission): array
    {
        return $this->db->row('SELECT uuid, state, anonymized_at FROM ' . $this->db->table('submissions') . ' WHERE id = :id AND form_id = :form FOR UPDATE', [':id' => $submission, ':form' => $form]) ?? throw new \OutOfBoundsException('Response not found.');
    }
    private function audit(int $actor, int $form, string $uuid, string $event, array $metadata): void
    {
        $this->db->insert('audit_log', ['correlation_id' => Uuid::create(), 'actor_id' => $actor, 'event_type' => 'submission.' . $event, 'form_id' => $form, 'submission_uuid' => $uuid, 'created_at' => gmdate('Y-m-d H:i:s'), 'safe_metadata' => CanonicalJson::encode($metadata)]);
    }
}
