<?php
declare(strict_types=1);
// Isolated pre-release fixture upgrade; never a production migration command.
define('_JEXEC', 1); $root = dirname(__DIR__);
require $root . '/build/joomla-6.0.0/libraries/vendor/autoload.php';
require $root . '/src/lib_nicode_form_studio/autoload.php';
$config = json_decode(ltrim(file_get_contents($root . '/build/database-test.json'), "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);
if ($config['host'] !== '127.0.0.1' || $config['port'] !== 13367 || $config['database'] !== 'formstudio_test') { throw new RuntimeException('Refusing non-isolated fixtures.'); }
preg_match('/CREATE TABLE IF NOT EXISTS `#__nicode_form_studio_version_field_policy`[\s\S]*?;/', file_get_contents($root . '/src/com_nicode_form_studio/administrator/sql/mysql/install.sql'), $ddl);
if (!isset($ddl[0])) { throw new RuntimeException('Policy schema missing.'); }
foreach (['formstudio_test' => 'nfs_', 'formstudio_joomla' => 'j6_', 'formstudio_scale' => 'scale_'] as $database => $prefix) {
    $driver = (new Joomla\Database\DatabaseFactory())->getDriver('mysql', ['host' => '127.0.0.1', 'port' => 13367, 'user' => $config['user'], 'password' => $config['password'], 'database' => $database, 'prefix' => $prefix, 'charset' => 'utf8mb4']);
    $driver->connect(); $driver->setQuery($ddl[0])->execute();
    $db = new Nicode\FormStudio\Infrastructure\Database\Connection($driver); $policies = new Nicode\FormStudio\Infrastructure\Database\VersionFieldPolicy($db); $last = 0; $count = 0;
    do {
        $rows = $db->rows('SELECT id, form_id FROM ' . $db->table('form_versions') . ' WHERE id > :last ORDER BY id LIMIT 100', [':last' => $last]);
        foreach ($rows as $row) { $policies->rebuild((int) $row['form_id'], (int) $row['id']); $last = (int) $row['id']; $count++; }
    } while (count($rows) === 100);
    echo $database . ': ' . $count . " historical policies rebuilt from verified snapshots.\n";
}
