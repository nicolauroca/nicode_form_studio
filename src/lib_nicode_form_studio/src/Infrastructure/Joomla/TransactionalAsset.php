<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Infrastructure\Joomla;

use Joomla\CMS\Table\Asset;

/**
 * Retains Joomla's nested-set implementation without MySQL LOCK TABLES, which
 * implicitly commits the caller's transaction. Use only within a transaction.
 * All FormStudio tree changes serialize on the existing Joomla root asset.
 * Joomla's ordinary table lock also waits for these transactional row locks.
 */
final class TransactionalAsset extends Asset
{
    protected function _lock()
    {
        $db = $this->getDatabase();
        $db->setQuery('SELECT id FROM ' . $db->quoteName('#__assets') . ' WHERE parent_id = 0 FOR UPDATE');
        if ($db->loadColumn() === []) { throw new \RuntimeException('Joomla root asset unavailable.'); }
        $this->_locked = true;
        return true;
    }

    protected function _unlock()
    {
        // Row locks belong to the surrounding transaction, including savepoints.
        $this->_locked = false;
        return true;
    }
}
