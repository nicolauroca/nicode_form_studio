<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Infrastructure\Joomla;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Table\Asset;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;
use Nicode\FormStudio\Infrastructure\Database\Connection;

final readonly class AssetManager
{
    public function __construct(private DatabaseInterface $database, private DispatcherInterface $events, private Connection $db) {}
    public function attach(int $form): int
    {
        return $this->db->transaction(fn (): int => $this->attachWithinTransaction($form));
    }

    /** Delete only a verified leaf owned by this form, inside the worker transaction. */
    public function remove(int $form): void
    {
        $this->db->transaction(function () use ($form): void {
            if ($this->db->rows('SELECT id FROM ' . $this->db->quote('#__assets') . ' WHERE parent_id = 0 FOR UPDATE') === []) { throw new \RuntimeException('Joomla root asset unavailable.'); }
            $row = $this->db->row('SELECT asset_id FROM ' . $this->db->table('forms') . ' WHERE id = :id', [':id' => $form]) ?? throw new \OutOfBoundsException('Form unavailable.');
            $asset = new TransactionalAsset($this->database, $this->events);
            if (!$asset->loadByName('com_nicode_form_studio.form.' . $form)) {
                if ($row['asset_id'] === null) { return; }
                throw new \DomainException('Native form asset unavailable.');
            }
            $parent = new Asset($this->database, $this->events);
            if (!$parent->loadByName('com_nicode_form_studio') || (int) $asset->id !== (int) $row['asset_id'] || (int) $asset->parent_id !== (int) $parent->id || (int) $asset->rgt !== (int) $asset->lft + 1) { throw new \DomainException('Unexpected form asset ownership.'); }
            if (!$asset->delete((int) $asset->id)) { throw new \RuntimeException('Unable to remove form asset.'); }
            Access::clearStatics();
        });
    }

    /** Copy explicit rules only between verified native form assets, within the caller's transaction. */
    public function copyPermissions(int $source, int $target): void
    {
        $rows = [];
        foreach ([$source, $target] as $form) {
            $row = $this->db->row('SELECT a.id, a.name, a.rules FROM ' . $this->db->table('forms') . ' f JOIN ' . $this->db->quote('#__assets') . ' a ON a.id = f.asset_id WHERE f.id = :id FOR UPDATE', [':id' => $form]);
            if (!$row || $row['name'] !== 'com_nicode_form_studio.form.' . $form) { throw new \DomainException('Native form asset unavailable.'); }
            $rows[$form] = $row;
        }
        $this->db->execute('UPDATE ' . $this->db->quote('#__assets') . ' SET rules = :rules WHERE id = :id', [':rules' => $rows[$source]['rules'], ':id' => (int) $rows[$target]['id']]);
        Access::clearStatics();
    }

    private function attachWithinTransaction(int $form): int
    {
        // Acquire the tree mutex before reading parent/child positions.
        if ($this->db->rows('SELECT id FROM ' . $this->db->quote('#__assets') . ' WHERE parent_id = 0 FOR UPDATE') === []) { throw new \RuntimeException('Joomla root asset unavailable.'); }
        $row = $this->db->row('SELECT name FROM ' . $this->db->table('forms') . ' WHERE id = :id', [':id' => $form]) ?? throw new \OutOfBoundsException('Form not found.');
        $parent = new Asset($this->database, $this->events);
        if (!$parent->loadByName('com_nicode_form_studio')) { throw new \DomainException('Component asset unavailable.'); }
        $asset = new TransactionalAsset($this->database, $this->events); $name = 'com_nicode_form_studio.form.' . $form;
        if (!$asset->loadByName($name)) { $asset->name = $name; $asset->rules = '{}'; $asset->setLocation((int) $parent->id, 'last-child'); }
        elseif ((int) $asset->parent_id !== (int) $parent->id) { throw new \DomainException('Form asset has an unexpected parent.'); }
        $asset->title = $row['name'];
        if (!$asset->check() || !$asset->store()) { throw new \RuntimeException('Unable to store form asset.'); }
        $this->db->execute('UPDATE ' . $this->db->table('forms') . ' SET asset_id = :asset WHERE id = :form', [':asset' => (int) $asset->id, ':form' => $form]);
        Access::clearStatics();
        return (int) $asset->id;
    }
}
