<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Domain\{CanonicalJson, ConcurrentEdit, DefinitionRemapper, Uuid};
use Nicode\FormStudio\Infrastructure\Database\{Connection, FormRepository};

final readonly class FormDuplicator
{
    public function __construct(private Connection $db, private FormRepository $forms, private FormAdministration $administration, private DefinitionRemapper $remapper, private \Closure $copyPermissions) {}
    public function duplicateElement(int $form, int $expectedRevision, string $element, int $actor): array
    {
        $record = $this->administration->edit($form, $actor);
        if ((int) $record['form']['draft_revision'] !== $expectedRevision) { throw new ConcurrentEdit(); }
        $copy = $this->remapper->duplicateBranch($record['draft'], $element);
        $revision = $this->administration->save($form, $expectedRevision, $copy['definition'], $actor);
        return ['id' => $form, 'revision' => $revision, 'draft' => $copy['definition'], 'selected' => $copy['root']];
    }
    public function duplicate(int $source, int $expectedRevision, int $actor, string $name, string $alias, int $version = 0): array
    {
        $this->administration->edit($source, $actor);
        return $this->db->transaction(function () use ($source, $expectedRevision, $actor, $name, $alias, $version): array {
            $original = $this->db->row('SELECT * FROM ' . $this->db->table('forms') . ' WHERE id = :id FOR UPDATE', [':id' => $source]);
            if (!$original || (int) $original['draft_revision'] !== $expectedRevision) { throw new ConcurrentEdit(); }
            if ($original['state'] === 'deleting') { throw new \DomainException('Form deletion is in progress.'); }
            $definition = $this->administration->snapshot($source, $version, $actor);
            $id = $this->administration->create($name, $alias, $actor);
            $copy = $this->remapper->duplicate($definition, $this->forms->get($id)['uuid'])['definition']; $copy['name'] = $name;
            $revision = $this->administration->save($id, 0, $copy, $actor);
            // The copy stays a draft; preserving visibility cannot accidentally make a private source public.
            $this->db->execute('UPDATE ' . $this->db->table('forms') . ' SET access = :access, language = :language, publish_up = :up, publish_down = :down WHERE id = :id', [':access' => (int) $original['access'], ':language' => $original['language'], ':up' => $original['publish_up'], ':down' => $original['publish_down'], ':id' => $id]);
            ($this->copyPermissions)($source, $id);
            $this->db->insert('audit_log', ['correlation_id' => Uuid::create(), 'actor_id' => $actor, 'event_type' => 'form.duplicate', 'form_id' => $id, 'submission_uuid' => null, 'created_at' => gmdate('Y-m-d H:i:s'), 'safe_metadata' => CanonicalJson::encode(['source_form_uuid' => $original['uuid'], 'source_version_id' => $version])]);
            return ['id' => $id, 'revision' => $revision];
        });
    }
}
