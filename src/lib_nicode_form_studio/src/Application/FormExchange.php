<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Domain\{CanonicalJson, ConcurrentEdit, DefinitionRemapper, Uuid};
use Nicode\FormStudio\Infrastructure\Database\{Connection, FormRepository};
use Nicode\FormStudio\Transfer\{DefinitionPackage, ImportPreview, ImportReviewToken};

/** Administrator definition transfer. Preview never mutates authoring or runtime state. */
final readonly class FormExchange
{
    public function __construct(private Connection $db, private FormRepository $forms, private FormAdministration $administration, private DefinitionPackage $packages, private ImportPreview $preview, private ImportReviewToken $tokens, private DefinitionRemapper $remapper, private \Closure $authorize, private ?\Nicode\FormStudio\Contract\LifecycleEventsInterface $events = null) {}

    public function export(int $form, int $version, int $actor, string $mode): array
    {
        return $this->db->transaction(function () use ($form, $version, $actor, $mode): array {
            $this->administration->edit($form, $actor);
            $this->db->row('SELECT id FROM ' . $this->db->table('forms') . ' WHERE id = :id FOR UPDATE', [':id' => $form]);
            $context = ['form_id' => $form, 'version_id' => $version, 'format' => 'definition-json', 'mode' => $mode];
            $this->events?->emit('BeforeExport', $context);
            $package = $this->packages->export($this->administration->snapshot($form, $version, $actor), $mode);
            $this->events?->emit('AfterExport', $context + ['definition_hash' => $package['definition_hash']]);
            return $package;
        });
    }
    public function preview(string $json, array $choices, int $actor): array
    {
        $this->access($actor); $this->choices($choices);
        return $this->db->transaction(function () use ($json, $choices, $actor): array {
            $package = $this->packages->decode($json);
            $existing = null; $target = null;
            if ($choices['policy'] !== 'duplicate') {
                $target = $this->db->row('SELECT id, draft_revision FROM ' . $this->db->table('forms') . ' WHERE uuid = :uuid FOR UPDATE', [':uuid' => $package['definition']['uuid']]);
                if ($target !== null) {
                    $record = $this->administration->edit((int) $target['id'], $actor); $existing = $record['draft'];
                    if ($choices['policy'] === 'update' && $choices['alias'] !== $record['form']['alias']) { throw new \InvalidArgumentException('An import update preserves the target alias.'); }
                }
            }
            if ($choices['policy'] !== 'update') { $this->access($actor, 'core.create'); }
            $analysis = $this->preview->analyze($json, $choices['policy'], $existing);
            $analysis['target_id'] = $target === null ? null : (int) $target['id'];
            $analysis['target_revision'] = $target === null ? null : (int) $target['draft_revision'];
            if ($choices['policy'] === 'update' && $choices['revision'] !== $analysis['target_revision']) { throw new ConcurrentEdit(); }
            foreach ($analysis['references'] as &$reference) {
                $row = $this->db->row('SELECT v.hash FROM ' . $this->db->table('option_set_versions') . ' v JOIN ' . $this->db->table('option_sets') . ' s ON s.id = v.option_set_id WHERE s.uuid = :uuid AND v.revision = :revision', [':uuid' => (string) $reference['resource_uuid'], ':revision' => (int) $reference['revision']]);
                $reference['compatible'] = is_string($reference['resource_hash']) && $row !== null && hash_equals($row['hash'], $reference['resource_hash']);
                if (!$reference['compatible']) { $analysis['conflicts'][] = ['code' => 'import.resource_incompatible', 'path' => $reference['path']]; }
            }
            unset($reference);
            if ($choices['policy'] !== 'duplicate') { array_push($analysis['conflicts'], ...$this->identityConflicts($package['definition'], $analysis['target_id'])); }
            $alias = $this->db->row('SELECT id FROM ' . $this->db->table('forms') . ' WHERE alias = :alias', [':alias' => $choices['alias']]);
            if ($choices['policy'] !== 'update' && $alias !== null) { $analysis['conflicts'][] = ['code' => 'import.alias_collision', 'path' => '/alias']; }
            $analysis['can_import_draft'] = $analysis['conflicts'] === [];
            $analysis['choices'] = $choices;
            $analysis['review_token'] = $this->tokens->issue($actor, $this->binding($analysis));
            return $analysis;
        });
    }
    public function import(string $json, array $choices, int $actor, string $token, bool $acknowledgeReview): array
    {
        return $this->db->transaction(function () use ($json, $choices, $actor, $token, $acknowledgeReview): array {
            $analysis = $this->preview($json, $choices, $actor);
            $this->tokens->verify($token, $actor, $this->binding($analysis));
            if (!$analysis['can_import_draft'] || ($analysis['requires_review'] && !$acknowledgeReview)) { throw new \DomainException('Resolve import conflicts and acknowledge configuration review before importing.'); }
            $draft = $analysis['package']['definition'];
            if ($choices['policy'] === 'update') {
                $id = $analysis['target_id']; $revision = $analysis['target_revision'];
            } else {
                $id = $this->administration->create($choices['name'], $choices['alias'], $actor); $revision = 0;
                if ($choices['policy'] === 'duplicate') { $draft = $this->remapper->duplicate($draft, $this->forms->get($id)['uuid'])['definition']; }
                else { $this->db->execute('UPDATE ' . $this->db->table('forms') . ' SET uuid = :uuid WHERE id = :id', [':uuid' => $draft['uuid'], ':id' => $id]); }
            }
            $draft['name'] = $choices['name'];
            $revision = $this->administration->save($id, $revision, $draft, $actor);
            $this->db->insert('audit_log', ['correlation_id' => Uuid::create(), 'actor_id' => $actor, 'event_type' => 'form.import', 'form_id' => $id, 'submission_uuid' => null, 'created_at' => gmdate('Y-m-d H:i:s'), 'safe_metadata' => CanonicalJson::encode(['mode' => $analysis['package']['mode'], 'policy' => $choices['policy'], 'definition_hash' => $analysis['package']['definition_hash'], 'review_acknowledged' => $acknowledgeReview])]);
            return ['id' => $id, 'revision' => $revision, 'review' => $analysis['package']['review']];
        });
    }
    private function binding(array $analysis): array
    {
        unset($analysis['review_token']);
        return ['analysis_hash' => hash('sha256', CanonicalJson::encode($analysis))];
    }
    private function choices(array $choices): void
    {
        if (array_diff(array_keys($choices), ['policy', 'name', 'alias', 'revision']) !== [] || !in_array($choices['policy'] ?? null, ['duplicate', 'update', 'conflict'], true) || !is_string($choices['name'] ?? null) || trim($choices['name']) === '' || mb_strlen($choices['name']) > 255 || !mb_check_encoding($choices['name'], 'UTF-8') || !is_string($choices['alias'] ?? null) || strlen($choices['alias']) > 255 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $choices['alias']) !== 1 || !is_int($choices['revision'] ?? null) || $choices['revision'] < 0) { throw new \InvalidArgumentException('Invalid import choices.'); }
    }
    private function access(int $actor, string $permission = 'formstudio.forms.manage'): void
    {
        if ($actor < 1 || !(($this->authorize)($actor, null, 'core.manage')) || !(($this->authorize)($actor, null, 'formstudio.forms.manage')) || !(($this->authorize)($actor, null, $permission))) { throw new \DomainException('Definition transfer access denied.'); }
    }
    private function identityConflicts(array $definition, ?int $target): array
    {
        $conflicts = [];
        foreach (['elements', 'fields', 'rules', 'actions'] as $table) {
            foreach (array_chunk(array_column($definition[$table], 'uuid'), 400) as $uuids) {
                $params = [':target' => $target ?? 0]; $placeholders = [];
                foreach ($uuids as $i => $uuid) { $placeholders[] = ':u' . $i; $params[':u' . $i] = $uuid; }
                if ($this->db->row('SELECT id FROM ' . $this->db->table($table) . ' WHERE form_id <> :target AND uuid IN (' . implode(',', $placeholders) . ') LIMIT 1', $params) !== null) { $conflicts[] = ['code' => 'import.child_uuid_collision', 'path' => '/' . $table]; break; }
            }
        }
        return $conflicts;
    }
}
