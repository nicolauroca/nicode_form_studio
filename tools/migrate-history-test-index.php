<?php
declare(strict_types=1);

// Only disposable pre-release fixtures. Released upgrades use versioned SQL.
$root = dirname(__DIR__);
foreach ([['', 13367, 'formstudio_test', ['formstudio_test' => 'nfs_', 'formstudio_joomla' => 'j6_', 'formstudio_lifecycle' => 'lc_']], ['-mysql8', 13373, 'formstudio_test_mysql8', ['formstudio_test_mysql8' => 'nfs_', 'formstudio_joomla_mysql8' => 'my_']], ['-postgresql', 13368, 'formstudio_test_pg', ['formstudio_test_pg' => 'nfs_', 'formstudio_joomla_pg' => 'pg_']]] as [$suffix, $port, $guard, $databases]) {
    $config = json_decode(ltrim(file_get_contents($root . '/build/database-test' . $suffix . '.json'), "\xEF\xBB\xBF"), true, 32, JSON_THROW_ON_ERROR);
    if ($config['host'] !== '127.0.0.1' || $config['port'] !== $port || $config['database'] !== $guard) { throw new RuntimeException('Refusing non-test fixture.'); }
    foreach ($databases as $database => $prefix) {
        $postgres = $suffix === '-postgresql';
        $pdo = new PDO(($postgres ? 'pgsql:host=127.0.0.1;port=' : 'mysql:host=127.0.0.1;port=') . $port . ';dbname=' . $database . ($postgres ? '' : ';charset=utf8mb4'), $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ([['action_runs', 'nfs_action_runs_i1', 'submission_id, id'], ['submissions', 'nfs_submissions_i8', 'form_id, id']] as [$entity, $index, $columns]) {
            $table = $prefix . 'nicode_form_studio_' . $entity;
            if ($postgres) { $pdo->exec('CREATE INDEX IF NOT EXISTS ' . $index . ' ON ' . $table . ' (' . $columns . ')'); }
            else {
                $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?'); $query->execute([$database, $table, $index]);
                if ((int) $query->fetchColumn() === 0) { $pdo->exec('CREATE INDEX ' . $index . ' ON ' . $table . ' (' . $columns . ')'); }
            }
        }
    }
}
echo "Scoped action-history indexes applied to isolated pre-release fixtures.\n";
