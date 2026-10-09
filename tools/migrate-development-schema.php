<?php
declare(strict_types=1);

// Local pre-release fixture migration only. No released extension exists yet.
$root = dirname(__DIR__);
$configuration = json_decode(ltrim(file_get_contents($root . '/build/database-test.json'), "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);
if ($configuration['host'] !== '127.0.0.1' || $configuration['port'] !== 13367 || $configuration['database'] !== 'formstudio_test') { throw new RuntimeException('Refusing non-isolated development database.'); }
foreach (['formstudio_test' => 'nfs_', 'formstudio_joomla' => 'j6_', 'formstudio_lifecycle' => 'lc_'] as $databaseName => $prefix) {
    $pdo = new PDO('mysql:host=127.0.0.1;port=13367;dbname=' . $databaseName . ';charset=utf8mb4', $configuration['user'], $configuration['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $schema = file_get_contents($root . '/src/com_nicode_form_studio/administrator/sql/mysql/install.sql');
    foreach (['technical_log', 'installation_state'] as $addedTable) {
        if (!preg_match('/CREATE TABLE IF NOT EXISTS `#__nicode_form_studio_' . $addedTable . '`.*?;/s', $schema, $addedDdl)) { throw new RuntimeException('Development DDL missing.'); }
        $pdo->exec(str_replace('#__', $prefix, $addedDdl[0]));
    }
    $table = $prefix . 'nicode_form_studio_jobs';
    $jobIndexCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?');
    $jobIndexCheck->execute([$databaseName, $table, 'nfs_jobs_i4']);
    if ((int) $jobIndexCheck->fetchColumn() === 0) { $pdo->exec('CREATE INDEX nfs_jobs_i4 ON `' . $table . '` (job_type, id)'); }
    $auditTable = $prefix . 'nicode_form_studio_audit_log';
    $auditCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?');
    $auditCheck->execute([$databaseName, $auditTable, 'nfs_audit_log_i2']);
    if ((int) $auditCheck->fetchColumn() === 0) { $pdo->exec('CREATE INDEX nfs_audit_log_i2 ON `' . $auditTable . '` (form_id, submission_uuid, id)'); }
    $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?'); $check->execute([$databaseName, $table, 'form_id']);
    if ((int) $check->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN form_id BIGINT NULL');
        $pdo->exec('CREATE INDEX dev_jobs_form_scope ON `' . $table . '` (form_id, job_type, state, id)');
        $pdo->exec('UPDATE `' . $table . '` SET form_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(parameters, \'$.form_id\')) AS SIGNED) WHERE JSON_EXTRACT(parameters, \'$.form_id\') IS NOT NULL');
    }
}
echo "Isolated pre-release job scope migration applied.\n";
