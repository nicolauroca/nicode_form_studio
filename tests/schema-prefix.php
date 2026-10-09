<?php
declare(strict_types=1);
define('_JEXEC', 1);
$root = dirname(__DIR__);
require $root . '/build/joomla-6.0.0/libraries/vendor/autoload.php';
$engine = $argv[1] ?? 'mysql';
if (!in_array($engine, ['mysql', 'mysql8', 'postgresql'], true)) { throw new InvalidArgumentException('Unknown isolated engine.'); }
$postgres = $engine === 'postgresql'; $suffix = $postgres ? '-postgresql' : ($engine === 'mysql8' ? '-mysql8' : '');
$config = json_decode(ltrim(file_get_contents($root . '/build/database-test' . $suffix . '.json'), "\xEF\xBB\xBF"), true, 32, JSON_THROW_ON_ERROR);
$expected = $postgres ? ['formstudio_test_pg', 13368] : ($engine === 'mysql8' ? ['formstudio_test_mysql8', 13373] : ['formstudio_test', 13367]);
if ($config['host'] !== '127.0.0.1' || [$config['database'], $config['port']] !== $expected) { throw new RuntimeException('Refusing non-isolated schema fixture.'); }
$pdo = new PDO(($postgres ? 'pgsql' : 'mysql') . ':host=127.0.0.1;port=' . $expected[1] . ';dbname=' . $expected[0], $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$dialect = $postgres ? 'postgresql' : 'mysql';
$install = file_get_contents($root . '/src/com_nicode_form_studio/administrator/sql/' . $dialect . '/install.sql');
$purge = file_get_contents($root . '/src/com_nicode_form_studio/administrator/sql/' . $dialect . '/purge.sql');
$probe = 'probe' . bin2hex(random_bytes(3)); $prefixes = [$probe . 'a_', $probe . 'b_'];
try {
    foreach ($prefixes as $prefix) {
        foreach (Joomla\Database\DatabaseDriver::splitSql(str_replace('#__', $prefix, $install)) as $statement) { if (trim($statement) !== '') { $pdo->exec($statement); } }
        $table = $prefix . 'nicode_form_studio_installation_state';
        $pdo->exec("INSERT INTO $table (state_key, state_json) VALUES ('probe', '{}')");
        try { $pdo->exec("INSERT INTO $table (state_key, state_json) VALUES ('probe', '{}')"); throw new LogicException('Scoped schema lost its unique constraint.'); }
        catch (PDOException $error) { if (!in_array($error->getCode(), ['23000', '23505'], true)) { throw $error; } }
        if ($postgres) {
            $query = $pdo->prepare('SELECT COUNT(*) FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?');
            $query->execute([$prefix . 'nicode_form_studio_forms']);
            if ((int) $query->fetchColumn() !== 5) { throw new RuntimeException('Scoped PostgreSQL schema silently skipped an index.'); }
        }
    }
} finally {
    foreach (array_reverse($prefixes) as $prefix) {
        if (!preg_match('/^probe[0-9a-f]{6}[ab]_$/D', $prefix)) { throw new LogicException('Unexpected cleanup scope.'); }
        foreach (Joomla\Database\DatabaseDriver::splitSql(str_replace('#__', $prefix, $purge)) as $statement) { if (trim($statement) !== '') { $pdo->exec($statement); } }
    }
}
echo ucfirst($engine) . " same-database installation: two prefixes, complete indexes, independent uniqueness and scoped cleanup passed.\n";
