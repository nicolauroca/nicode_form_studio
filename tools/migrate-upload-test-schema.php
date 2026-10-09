<?php
declare(strict_types=1);

// Add only the new ownership table to disposable pre-release fixtures.
$root = dirname(__DIR__);
require $root . '/build/joomla-6.0.0/libraries/vendor/autoload.php';
foreach ([['', 13367, 'formstudio_test', ['formstudio_test' => 'nfs_', 'formstudio_joomla' => 'j6_', 'formstudio_lifecycle' => 'lc_']], ['-mysql8', 13373, 'formstudio_test_mysql8', ['formstudio_test_mysql8' => 'nfs_', 'formstudio_joomla_mysql8' => 'my_']], ['-postgresql', 13368, 'formstudio_test_pg', ['formstudio_test_pg' => 'nfs_', 'formstudio_joomla_pg' => 'pg_']]] as [$suffix, $port, $guard, $databases]) {
    $config = json_decode(ltrim(file_get_contents($root . '/build/database-test' . $suffix . '.json'), "\xEF\xBB\xBF"), true, 32, JSON_THROW_ON_ERROR);
    if ($config['host'] !== '127.0.0.1' || $config['port'] !== $port || $config['database'] !== $guard) { throw new RuntimeException('Refusing non-test fixture.'); }
    $postgres = $suffix === '-postgresql';
    $schema = file_get_contents($root . '/src/com_nicode_form_studio/administrator/sql/' . ($postgres ? 'postgresql' : 'mysql') . '/install.sql');
    $statements = array_values(array_filter(Joomla\Database\DatabaseDriver::splitSql($schema), static fn (string $sql): bool => str_contains($sql, '#__nicode_form_studio_upload_staging')));
    if (count($statements) !== ($postgres ? 3 : 1)) { throw new RuntimeException('Unexpected staging DDL.'); }
    foreach ($databases as $database => $prefix) {
        $pdo = new PDO(($postgres ? 'pgsql:host=127.0.0.1;port=' : 'mysql:host=127.0.0.1;port=') . $port . ';dbname=' . $database . ($postgres ? '' : ';charset=utf8mb4'), $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ($statements as $statement) { $pdo->exec(str_replace('#__', $prefix, $statement)); }
    }
}
echo "Upload ownership schema applied to seven isolated pre-release fixtures.\n";
