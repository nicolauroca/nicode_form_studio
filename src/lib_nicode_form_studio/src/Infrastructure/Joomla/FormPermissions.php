<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Infrastructure\Joomla;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Access\Rules;
use Nicode\FormStudio\Domain\{CanonicalJson, ConcurrentEdit, Uuid};
use Nicode\FormStudio\Infrastructure\Database\Connection;
use Nicode\FormStudio\Security\Permissions;

/** Revisioned edits to native ACL rules; nested-set coordinates are untouched. */
final readonly class FormPermissions
{
    public function __construct(private Connection $db, private Authorization $authorization) {}

    public function read(int $form, int $actor, int $group): array
    {
        $this->assert($form, $actor); $row = $this->asset($form); $this->group($group);
        $native = new Rules($row['rules']); $permissions = [];
        Access::clearStatics();
        foreach (self::actions() as $action) {
            $permissions[$action] = ['direct' => $native->allow($action, $group), 'effective' => (bool) Access::checkGroup($group, $action, (int) $row['asset_id'])];
        }
        return ['revision' => (int) $row['draft_revision'], 'rules_hash' => hash('sha256', $row['rules']), 'group' => $group, 'permissions' => $permissions,
            'groups' => $this->db->rows('SELECT id, title, parent_id FROM ' . $this->db->quote('#__usergroups') . ' ORDER BY lft')];
    }

    public function update(int $form, int $revision, string $expectedHash, int $actor, int $group, array $changes): int
    {
        $this->assert($form, $actor);
        if ($revision < 0 || preg_match('/^[a-f0-9]{64}$/D', $expectedHash) !== 1 || $changes === [] || array_diff(array_keys($changes), self::actions()) !== []) { throw new \InvalidArgumentException('Invalid permission change.'); }
        foreach ($changes as $value) { if ($value !== null && !is_bool($value)) { throw new \InvalidArgumentException('Permission must be allow, deny or inherit.'); } }
        $this->group($group);
        $next = $this->db->transaction(function () use ($form, $revision, $expectedHash, $actor, $group, $changes): int {
            $changed = $this->db->execute('UPDATE ' . $this->db->table('forms') . ' SET draft_revision = draft_revision + 1, modified_at = :now, modified_by = :actor WHERE id = :id AND draft_revision = :revision AND state <> :deleting', [':now' => gmdate('Y-m-d H:i:s'), ':actor' => $actor, ':id' => $form, ':deleting' => 'deleting', ':revision' => $revision]);
            if ($changed !== 1) { throw new ConcurrentEdit(); }
            $row = $this->asset($form, true);
            if (!hash_equals($expectedHash, hash('sha256', $row['rules']))) { throw new ConcurrentEdit(); }
            $rules = json_decode($row['rules'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($rules)) { throw new \DomainException('Invalid native ACL rules.'); }
            foreach ($changes as $action => $value) {
                if ($value === null) { unset($rules[$action][$group]); if (($rules[$action] ?? []) === []) { unset($rules[$action]); } }
                else { $rules[$action][$group] = (int) $value; }
            }
            $native = new Rules($rules);
            $this->db->execute('UPDATE ' . $this->db->quote('#__assets') . ' SET rules = :rules WHERE id = :asset AND name = :name', [':rules' => (string) $native, ':asset' => (int) $row['asset_id'], ':name' => 'com_nicode_form_studio.form.' . $form]);
            $this->db->insert('audit_log', ['correlation_id' => Uuid::create(), 'actor_id' => $actor, 'event_type' => 'form.permissions', 'form_id' => $form, 'submission_uuid' => null, 'created_at' => gmdate('Y-m-d H:i:s'), 'safe_metadata' => CanonicalJson::encode(['group_id' => $group, 'revision' => $revision + 1, 'changes' => $changes])]);
            return $revision + 1;
        });
        Access::clearStatics();
        return $next;
    }

    private function assert(int $form, int $actor): void
    {
        $this->authorization->assert($actor, null, 'core.manage');
        $this->authorization->assert($actor, null, 'core.admin');
        $this->authorization->assert($actor, $form, 'formstudio.forms.manage');
    }
    private function group(int $group): void
    {
        if ($group < 1 || $this->db->row('SELECT id FROM ' . $this->db->quote('#__usergroups') . ' WHERE id = :id', [':id' => $group]) === null) { throw new \InvalidArgumentException('Unknown Joomla group.'); }
    }
    private function asset(int $form, bool $lock = false): array
    {
        return $this->db->row('SELECT f.asset_id, f.draft_revision, a.rules FROM ' . $this->db->table('forms') . ' f JOIN ' . $this->db->quote('#__assets') . ' a ON a.id = f.asset_id WHERE f.id = :id AND a.name = :name' . ($lock ? ' FOR UPDATE' : ''), [':id' => $form, ':name' => 'com_nicode_form_studio.form.' . $form]) ?? throw new \OutOfBoundsException('Form asset unavailable.');
    }
    public static function actions(): array { return array_values(array_diff(Permissions::ALL, ['core.admin', 'core.options', 'formstudio.resources.manage'])); }
}
