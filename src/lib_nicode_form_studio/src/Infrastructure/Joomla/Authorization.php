<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Infrastructure\Joomla;

use Joomla\CMS\User\UserFactoryInterface;
use Nicode\FormStudio\Infrastructure\Database\Connection;
use Nicode\FormStudio\Security\Permissions;

final readonly class Authorization
{
    public function __construct(private UserFactoryInterface $users, private Connection $db) {}
    public function allows(int $actor, ?int $form, string $permission): bool
    {
        if ($actor < 1 || !in_array($permission, Permissions::ALL, true)) { return false; }
        $asset = 'com_nicode_form_studio';
        if ($form !== null) {
            if ($form < 1) { return false; }
            $row = $this->db->row('SELECT a.name FROM ' . $this->db->table('forms') . ' f JOIN ' . $this->db->quote('#__assets') . ' a ON a.id = f.asset_id WHERE f.id = :id', [':id' => $form]);
            if (!$row || $row['name'] !== $asset . '.form.' . $form) { return false; }
            $asset = $row['name'];
        }
        $user = $this->users->loadUserById($actor);
        return (int) $user->id === $actor && !(bool) $user->block && (bool) $user->authorise($permission, $asset);
    }
    public function assert(int $actor, ?int $form, string $permission): void
    {
        if (!$this->allows($actor, $form, $permission)) { throw new \DomainException('Permission denied.'); }
    }

    /** Trusted forms/assets rows; response readers need no form editing permission. */
    public function submissionCapabilities(int $actor, array $rows): array
    {
        if ($actor < 1) { return []; }
        $user = $this->users->loadUserById($actor);
        if ((int) $user->id !== $actor || $user->block || !$user->authorise('core.manage', 'com_nicode_form_studio')) { return []; }
        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row['id']; $asset = $row['asset_name'] ?? '';
            if ($id < 1 || $asset !== 'com_nicode_form_studio.form.' . $id || !$user->authorise('formstudio.submissions.view', $asset)) { continue; }
            $result[$id] = [];
            foreach (['view_sensitive', 'manage', 'export', 'delete', 'anonymize', 'retry', 'reindex'] as $action) {
                $result[$id]['formstudio.submissions.' . $action] = (bool) $user->authorise('formstudio.submissions.' . $action, $asset);
            }
        }
        return $result;
    }

    /** Rows must come from a forms/assets join, never from browser input. */
    public function formCapabilities(int $actor, array $rows): array
    {
        if ($actor < 1) { return []; }
        $user = $this->users->loadUserById($actor);
        if ((int) $user->id !== $actor || $user->block || !$user->authorise('core.manage', 'com_nicode_form_studio')) { return []; }
        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row['id']; $asset = $row['asset_name'] ?? '';
            if ($id < 1 || $asset !== 'com_nicode_form_studio.form.' . $id || !$user->authorise('formstudio.forms.manage', $asset)) { continue; }
            $result[$id] = [];
            foreach (['core.edit', 'core.edit.state', 'core.delete', 'formstudio.forms.publish', 'formstudio.submissions.view', 'formstudio.submissions.export'] as $permission) { $result[$id][$permission] = (bool) $user->authorise($permission, $asset); }
        }
        return $result;
    }
}
