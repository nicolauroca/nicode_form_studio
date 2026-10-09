<?php
declare(strict_types=1);
defined('_JEXEC') or die;

use Joomla\CMS\Installer\{InstallerAdapter, InstallerScriptInterface};
use Joomla\Database\{DatabaseAwareInterface, DatabaseAwareTrait, ParameterType};

/** Self-contained: the package may remove its library before this component. */
return new class implements InstallerScriptInterface, DatabaseAwareInterface {
    use DatabaseAwareTrait;
    private bool $purge = false;
    public function install(InstallerAdapter $adapter): bool { return true; }
    public function update(InstallerAdapter $adapter): bool { return true; }
    public function uninstall(InstallerAdapter $adapter): bool
    {
        if (!$this->purge) { return true; }
        $db = $this->getDatabase();
        if (!NicodeFormStudioPurgeGuard::check($db)) { throw new RuntimeException('Purge authorization disappeared.'); }
        $dialect = $db->getServerType();
        if (!in_array($dialect, ['mysql', 'postgresql'], true)) { throw new RuntimeException('Unsupported purge database.'); }
        $sql = file_get_contents(__DIR__ . '/sql/' . $dialect . '/purge.sql');
        if (!is_string($sql)) { throw new RuntimeException('Purge schema script unavailable.'); }
        $cache = \Joomla\CMS\Factory::getContainer()->get(\Joomla\CMS\Cache\CacheControllerFactoryInterface::class)->createCacheController('output', ['defaultgroup' => 'com_nicode_form_studio.sources']);
        if (!$cache->clean('com_nicode_form_studio.sources')) { throw new RuntimeException('Source cache cleanup failed; retry purge after restoring the cache provider.'); }
        foreach (\Joomla\Database\DatabaseDriver::splitSql($sql) as $statement) { if (trim($statement) !== '') { $db->setQuery($statement)->execute(); } }
        return true;
    }
    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'uninstall') {
            require_once __DIR__ . '/purgeguard.php';
            $this->purge = NicodeFormStudioPurgeGuard::check($this->getDatabase());
            if (!$this->purge) { $this->preserve(); }
            return true;
        }
        if (version_compare(PHP_VERSION, '8.3.0', '<') || version_compare(JVERSION, '6.0.0', '<')) { throw new RuntimeException('Nicode Form Studio requires PHP 8.3 and Joomla 6 or newer.'); }
        if (!is_file(JPATH_LIBRARIES . '/nicode_form_studio/autoload.php')) { throw new RuntimeException('Install the complete Nicode Form Studio package.'); }
        return true;
    }
    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'uninstall') { return true; }
        $this->ensureHistoryIndexes();
        require_once JPATH_LIBRARIES . '/nicode_form_studio/autoload.php';
        \Nicode\FormStudio\Infrastructure\Database\IdentitySchema::upgrade($this->getDatabase());
        \Nicode\FormStudio\Infrastructure\Database\InstanceSchema::upgrade($this->getDatabase());
        $state = $this->rows('SELECT state_json FROM #__nicode_form_studio_installation_state WHERE state_key = :key', [':key' => 'component']);
        if ($state !== []) { $this->restore(json_decode($state[0]['state_json'], true, 512, JSON_THROW_ON_ERROR)); }
        $this->execute('DELETE FROM #__nicode_form_studio_installation_state WHERE state_key = :key', [':key' => 'schema']);
        $this->save('schema', ['version' => '1.0.0']);
        return true;
    }
    private function preserve(): void
    {
        $db = $this->getDatabase(); $db->transactionStart();
        try {
            $extension = $this->rows("SELECT params FROM #__extensions WHERE type = 'component' AND element = 'com_nicode_form_studio'")[0] ?? throw new RuntimeException('Component state unavailable.');
            $root = $this->rows("SELECT rules FROM #__assets WHERE name = 'com_nicode_form_studio'")[0] ?? throw new RuntimeException('Component permissions unavailable.');
            $this->execute('DELETE FROM #__nicode_form_studio_installation_state');
            $this->save('component', ['params' => $extension['params'], 'rules' => $root['rules'], 'schema' => '1.0.0']);
            $last = 0;
            do {
                $rows = $this->rows('SELECT f.id, f.name, a.name AS asset_name, a.rules FROM #__nicode_form_studio_forms f LEFT JOIN #__assets a ON a.id = f.asset_id WHERE f.id > :last ORDER BY f.id LIMIT 200', [':last' => $last]);
                foreach ($rows as $row) {
                    $last = (int) $row['id'];
                    $this->save('form:' . $last, ['id' => $last, 'name' => $row['name'], 'rules' => $row['rules'], 'valid' => $row['asset_name'] === 'com_nicode_form_studio.form.' . $last]);
                }
            } while (count($rows) === 200);
            $db->transactionCommit();
        } catch (Throwable $error) { $db->transactionRollback(); throw $error; }
    }

    /** Pre-release schema completion also applies to same-version development upgrades. */
    private function ensureHistoryIndexes(): void
    {
        $db = $this->getDatabase();
        foreach (['action_runs' => 'nfs_action_runs_i2', 'audit_log' => 'nfs_audit_log_i3'] as $table => $index) {
            if ($db->getServerType() === 'postgresql') { $index = $db->replacePrefix('#__' . $index); }
            $name = $db->replacePrefix('#__nicode_form_studio_' . $table);
            $keys = $db->getTableKeys($name); $found = false;
            foreach ($keys as $key) { if (($key->Key_name ?? $key->idxName ?? null) === $index) { $found = true; break; } }
            if (!$found) { $db->setQuery('CREATE INDEX ' . $db->quoteName($index) . ' ON ' . $db->quoteName($name) . ' (' . $db->quoteName('created_at') . ', ' . $db->quoteName('id') . ')')->execute(); }
        }
    }
    private function restore(array $component): void
    {
        if (($component['schema'] ?? null) !== '1.0.0') { throw new RuntimeException('Preserved schema requires a supported migration.'); }
        $db = $this->getDatabase(); $root = new Joomla\CMS\Table\Asset($db);
        if (!$root->loadByName('com_nicode_form_studio')) { throw new RuntimeException('Restoration requires the component asset.'); }
        $last = 0;
        do {
            $rows = $this->rows("SELECT id, state_json FROM #__nicode_form_studio_installation_state WHERE state_key LIKE 'form:%' AND id > :last ORDER BY id LIMIT 200", [':last' => $last]);
            foreach ($rows as $row) {
                $last = (int) $row['id']; $saved = json_decode($row['state_json'], true, 512, JSON_THROW_ON_ERROR);
                if ($this->rows('SELECT id FROM #__nicode_form_studio_forms WHERE id = :id', [':id' => (int) $saved['id']]) === []) { continue; }
                $assetId = null;
                if ($saved['valid']) {
                    $asset = new Joomla\CMS\Table\Asset($db); $name = 'com_nicode_form_studio.form.' . (int) $saved['id'];
                    if (!$asset->loadByName($name)) { $asset->name = $name; $asset->setLocation((int) $root->id, 'last-child'); }
                    elseif ((int) $asset->parent_id !== (int) $root->id) { throw new RuntimeException('Unexpected preserved asset parent.'); }
                    $asset->title = $saved['name']; $asset->rules = $saved['rules'];
                    if (!$asset->check() || !$asset->store()) { throw new RuntimeException('Unable to restore form permissions.'); }
                    $assetId = (int) $asset->id;
                }
                $this->execute('UPDATE #__nicode_form_studio_forms SET asset_id = :asset WHERE id = :id', [':asset' => $assetId, ':id' => (int) $saved['id']]);
            }
        } while (count($rows) === 200);
        if (!$root->loadByName('com_nicode_form_studio')) { throw new RuntimeException('Component asset disappeared during restoration.'); }
        $root->rules = $component['rules']; if (!$root->check() || !$root->store()) { throw new RuntimeException('Unable to restore component permissions.'); }
        $this->execute("UPDATE #__extensions SET params = :params WHERE type = 'component' AND element = 'com_nicode_form_studio'", [':params' => $component['params']]);
        $this->execute('DELETE FROM #__nicode_form_studio_installation_state');
        Joomla\CMS\Access\Access::clearStatics();
    }
    private function save(string $key, array $value): void
    {
        $this->execute('INSERT INTO #__nicode_form_studio_installation_state (state_key, state_json) VALUES (:key, :value)', [':key' => $key, ':value' => json_encode($value, JSON_THROW_ON_ERROR)]);
    }
    private function query(string $sql, array $parameters): void
    {
        $db = $this->getDatabase(); $query = $db->createQuery()->setQuery($sql);
        foreach ($parameters as $key => &$value) { $query->bind($key, $value, $value === null ? ParameterType::NULL : (is_int($value) ? ParameterType::INTEGER : ParameterType::STRING)); } unset($value);
        $db->setQuery($query);
    }
    private function rows(string $sql, array $parameters = []): array { $this->query($sql, $parameters); return $this->getDatabase()->loadAssocList(); }
    private function execute(string $sql, array $parameters = []): void { $this->query($sql, $parameters); $this->getDatabase()->execute(); }
};
