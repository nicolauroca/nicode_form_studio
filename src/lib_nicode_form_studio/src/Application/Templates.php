<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Actions\ReusableEmailTemplate;
use Nicode\FormStudio\Domain\{CanonicalJson, ConcurrentEdit, Uuid};
use Nicode\FormStudio\Infrastructure\Database\Connection;

/** Templates are copied into drafts; published forms never read mutable resources. */
final readonly class Templates
{
    public function __construct(private Connection $db, private FormAdministration $forms, private FormExchange $exchange, private \Closure $authorize) {}
    public function listing(int $actor, string $kind, int $before = PHP_INT_MAX): array
    {
        $this->access($actor); $table = $this->table($kind);
        if ($before < 1) { throw new \InvalidArgumentException('Invalid template cursor.'); }
        $rows = $this->db->rows('SELECT id, uuid, name, revision' . ($kind === 'email' ? ', language' : '') . ' FROM ' . $this->db->table($table) . ' WHERE id < :before ORDER BY id DESC LIMIT 101', [':before' => $before]);
        $more = count($rows) > 100; if ($more) { array_pop($rows); }
        return ['kind' => $kind, 'rows' => $rows, 'next_before' => $more ? (int) end($rows)['id'] : null, 'can_edit' => (bool) ($this->authorize)($actor, null, 'formstudio.resources.manage')];
    }
    public function read(int $actor, string $kind, int $id): array
    {
        $this->access($actor); $table = $this->table($kind);
        $record = $this->db->row('SELECT * FROM ' . $this->db->table($table) . ' WHERE id = :id', [':id' => $id]) ?? throw new \OutOfBoundsException('Template unavailable.');
        $record['id'] = (int) $record['id']; $record['revision'] = (int) $record['revision'];
        if ($kind === 'email') { $record['parameters'] = (new ReusableEmailTemplate())->validate($this->content($record)); }
        else { $record['package'] = json_decode($record['definition'], true, 128, JSON_THROW_ON_ERROR); unset($record['definition']); }
        return $record;
    }
    public function createEmail(int $actor, string $name): int
    {
        $this->access($actor, true); $this->name($name);
        return $this->db->transaction(function () use ($actor, $name): int {
            $id = $this->db->insert('email_templates', ['uuid' => Uuid::create(), 'name' => $name, 'language' => 'en-GB', 'subject' => '', 'body_text' => '', 'body_html' => '', 'revision' => 0]);
            $this->audit($actor, 'email', $id, 0, 'create'); return $id;
        });
    }
    public function saveEmail(int $actor, int $id, int $expected, string $name, string $language, array $content): int
    {
        $this->access($actor, true); $this->name($name); (new ReusableEmailTemplate())->validate($content);
        if (preg_match('/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8}){0,3}$/D', $language) !== 1) { throw new \InvalidArgumentException('Invalid template language.'); }
        return $this->db->transaction(function () use ($actor, $id, $expected, $name, $language, $content): int {
            $this->lock('email', $id, $expected); $next = $expected + 1;
            $this->db->execute('UPDATE ' . $this->db->table('email_templates') . ' SET name = :name, language = :language, subject = :subject, body_text = :text, body_html = :html, revision = :revision WHERE id = :id', [':name' => $name, ':language' => $language, ':subject' => $content['subject'], ':text' => $content['body_text'], ':html' => $content['body_html'] ?? '', ':revision' => $next, ':id' => $id]);
            $this->audit($actor, 'email', $id, $next, 'save'); return $next;
        });
    }
    public function emailConfiguration(int $actor, int $id, int $expected, int $form, array $bindings): array
    {
        $record = $this->read($actor, 'email', $id);
        if ($expected < 1 || $record['revision'] !== $expected) { throw new ConcurrentEdit('Selected template revision is no longer current.'); }
        $definition = $this->forms->edit($form, $actor)['draft'];
        $config = (new ReusableEmailTemplate())->bind($this->content($record), $definition, $bindings);
        $config['template'] = ['uuid' => $record['uuid'], 'revision' => $record['revision'], 'hash' => hash('sha256', CanonicalJson::encode(array_diff_key($record, ['id' => true, 'parameters' => true])))];
        return ['language' => $record['language'], 'config' => $config];
    }
    public function captureForm(int $actor, string $name, int $form, int $formRevision, int $id = 0, int $expected = 0): array
    {
        $this->access($actor, true); $this->name($name);
        return $this->db->transaction(function () use ($actor, $name, $form, $formRevision, $id, $expected): array {
            $this->db->row('SELECT id FROM ' . $this->db->table('forms') . ' WHERE id = :id FOR UPDATE', [':id' => $form]);
            $source = $this->forms->edit($form, $actor);
            if ((int) $source['form']['draft_revision'] !== $formRevision) { throw new ConcurrentEdit('Source draft changed.'); }
            $package = $this->exchange->export($form, 0, $actor, 'portable');
            if ($id === 0) {
                if ($expected !== 0) { throw new \InvalidArgumentException('Unexpected new template revision.'); }
                $next = 1; $id = $this->db->insert('form_templates', ['uuid' => Uuid::create(), 'name' => $name, 'definition' => CanonicalJson::encode($package), 'revision' => $next]);
            } else {
                $this->lock('form', $id, $expected); $next = $expected + 1;
                $this->db->execute('UPDATE ' . $this->db->table('form_templates') . ' SET name = :name, definition = :definition, revision = :revision WHERE id = :id', [':name' => $name, ':definition' => CanonicalJson::encode($package), ':revision' => $next, ':id' => $id]);
            }
            $this->audit($actor, 'form', $id, $next, 'save'); return ['id' => $id, 'revision' => $next, 'review' => $package['review']];
        });
    }
    public function previewForm(int $actor, int $id, int $expected, string $name, string $alias): array
    {
        $record = $this->read($actor, 'form', $id);
        if ($record['revision'] !== $expected) { throw new ConcurrentEdit('Selected template changed.'); }
        return $this->exchange->preview(CanonicalJson::encode($record['package']), $this->choices($name, $alias), $actor);
    }
    public function createForm(int $actor, int $id, int $expected, string $name, string $alias, string $token, bool $reviewed): array
    {
        $this->access($actor);
        return $this->db->transaction(function () use ($actor, $id, $expected, $name, $alias, $token, $reviewed): array {
            $this->lock('form', $id, $expected); $record = $this->read($actor, 'form', $id);
            $result = $this->exchange->import(CanonicalJson::encode($record['package']), $this->choices($name, $alias), $actor, $token, $reviewed);
            $this->audit($actor, 'form', $id, $expected, 'apply'); return $result;
        });
    }
    private function choices(string $name, string $alias): array { return ['policy' => 'duplicate', 'name' => $name, 'alias' => $alias, 'revision' => 0]; }
    private function content(array $record): array { return array_intersect_key($record, array_flip(['subject', 'body_text', 'body_html'])); }
    private function lock(string $kind, int $id, int $expected): void
    {
        $row = $this->db->row('SELECT revision FROM ' . $this->db->table($this->table($kind)) . ' WHERE id = :id FOR UPDATE', [':id' => $id]) ?? throw new \OutOfBoundsException('Template unavailable.');
        if ($expected < 0 || (int) $row['revision'] !== $expected) { throw new ConcurrentEdit('Template changed.'); }
    }
    private function table(string $kind): string { return match ($kind) { 'email' => 'email_templates', 'form' => 'form_templates', default => throw new \InvalidArgumentException('Unknown template kind.') }; }
    private function name(string $name): void { if (trim($name) === '' || mb_strlen($name, 'UTF-8') > 255 || !mb_check_encoding($name, 'UTF-8')) { throw new \InvalidArgumentException('Invalid template name.'); } }
    private function access(int $actor, bool $write = false): void
    {
        if (!(($this->authorize)($actor, null, 'core.manage')) || (!(($this->authorize)($actor, null, 'formstudio.resources.manage')) && ($write || !(($this->authorize)($actor, null, 'formstudio.forms.manage'))))) { throw new \DomainException('Templates unavailable.'); }
    }
    private function audit(int $actor, string $kind, int $id, int $revision, string $operation): void
    {
        $this->db->insert('audit_log', ['correlation_id' => Uuid::create(), 'actor_id' => $actor, 'event_type' => 'resource.' . $kind . '_template.' . $operation, 'form_id' => null, 'submission_uuid' => null, 'created_at' => gmdate('Y-m-d H:i:s'), 'safe_metadata' => CanonicalJson::encode(['resource_id' => $id, 'revision' => $revision])]);
    }
}
