<?php
declare(strict_types=1);
defined('_JEXEC') or die;
use Joomla\CMS\Installer\{InstallerAdapter, InstallerScriptInterface};
use Joomla\Database\{DatabaseAwareInterface, DatabaseAwareTrait};
return new class implements InstallerScriptInterface, DatabaseAwareInterface {
    use DatabaseAwareTrait;
    public function install(InstallerAdapter $adapter): bool { return true; }
    public function update(InstallerAdapter $adapter): bool { return true; }
    public function uninstall(InstallerAdapter $adapter): bool { return true; }
    public function preflight(string $type, InstallerAdapter $adapter): bool { return true; }
    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'install') {
            $db = $this->getDatabase();
            $db->setQuery("UPDATE #__extensions SET enabled = 1 WHERE type = 'plugin' AND folder = 'extension' AND element = 'nicode_form_studio'")->execute();
        }
        return true;
    }
};
