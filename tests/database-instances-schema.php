<?php
declare(strict_types=1);

// Disposable tables in the already guarded isolated database, never user data.
$instanceDriver = (new Joomla\Database\DatabaseFactory())->getDriver($postgres ? 'pgsql' : 'mysql', ['host' => '127.0.0.1', 'port' => $port, 'user' => $config['user'], 'password' => $config['password'], 'database' => $databaseName, 'prefix' => 'im_' . bin2hex(random_bytes(4)) . '_', 'charset' => 'utf8mb4']);
$instanceDb = new Nicode\FormStudio\Infrastructure\Database\Connection($instanceDriver);
$instanceIndex = $instanceDb->table('submission_index'); $instanceFiles = $instanceDb->table('submission_files');
try {
    $instanceDb->execute('CREATE TABLE ' . $instanceIndex . ' (id BIGINT PRIMARY KEY, submission_id BIGINT NOT NULL, field_uuid CHAR(36) NOT NULL, ordinal INTEGER NOT NULL, value_keyword VARCHAR(255), CONSTRAINT ' . $instanceDb->quote('#__old_scope_unique') . ' UNIQUE (submission_id, field_uuid, ordinal))' . ($postgres ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'));
    $instanceDb->execute('CREATE TABLE ' . $instanceFiles . ' (id BIGINT PRIMARY KEY, storage_key VARCHAR(255) NOT NULL)' . ($postgres ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'));
    $instanceField = Nicode\FormStudio\Domain\Uuid::create();
    $instanceDb->execute('INSERT INTO ' . $instanceIndex . ' (id, submission_id, field_uuid, ordinal, value_keyword) VALUES (1, 1, :field, 0, :value)', [':field' => $instanceField, ':value' => 'Conservar ñ 🦉']);
    $instanceDb->execute('INSERT INTO ' . $instanceFiles . ' (id, storage_key) VALUES (1, :key)', [':key' => 'private/existing-file']);
    Nicode\FormStudio\Infrastructure\Database\InstanceSchema::upgrade($instanceDriver);
    Nicode\FormStudio\Infrastructure\Database\InstanceSchema::upgrade($instanceDriver);
    $instanceLegacy = $instanceDb->row('SELECT * FROM ' . $instanceIndex . ' WHERE id = 1');
    $instanceFile = $instanceDb->row('SELECT * FROM ' . $instanceFiles . ' WHERE id = 1');
    if ($instanceLegacy['instance_path'] !== '' || $instanceLegacy['instance_hash'] !== hash('sha256', '') || $instanceLegacy['value_keyword'] !== 'Conservar ñ 🦉'
        || $instanceFile['instance_path'] !== '' || $instanceFile['instance_hash'] !== hash('sha256', '') || $instanceFile['storage_key'] !== 'private/existing-file') { throw new RuntimeException('Instance migration changed legacy data.'); }
    $instancePaths = [];
    for ($i = 0; $i < 2; $i++) {
        $path = Nicode\FormStudio\Domain\Uuid::create() . '/' . Nicode\FormStudio\Domain\Uuid::create(); $instancePaths[] = $path;
        $instanceDb->execute('INSERT INTO ' . $instanceIndex . ' (id, submission_id, field_uuid, ordinal, instance_path, instance_hash) VALUES (:id, 1, :field, 0, :path, :hash)', [':id' => $i + 2, ':field' => $instanceField, ':path' => $path, ':hash' => hash('sha256', $path)]);
    }
    $duplicateRejected = false;
    try { $instanceDb->execute('INSERT INTO ' . $instanceIndex . ' (id, submission_id, field_uuid, ordinal, instance_path, instance_hash) VALUES (4, 1, :field, 0, :path, :hash)', [':field' => $instanceField, ':path' => $instancePaths[0], ':hash' => hash('sha256', $instancePaths[0])]); }
    catch (Throwable) { $duplicateRejected = true; }
    if (!$duplicateRejected) { throw new RuntimeException('Duplicate instance ordinal accepted.'); }
    // The full 64-ancestor scope survives without truncation in both tables.
    $longPath = implode('/', array_map(static fn () => Nicode\FormStudio\Domain\Uuid::create(), range(1, 128)));
    foreach ([$instanceIndex, $instanceFiles] as $table) {
        $instanceDb->execute('UPDATE ' . $table . ' SET instance_path = :path, instance_hash = :hash WHERE id = 1', [':path' => $longPath, ':hash' => hash('sha256', $longPath)]);
        if ($instanceDb->row('SELECT instance_path FROM ' . $table . ' WHERE id = 1')['instance_path'] !== $longPath) { throw new RuntimeException('Instance path truncated.'); }
    }
    Nicode\FormStudio\Infrastructure\Database\InstanceSchema::upgrade($instanceDriver);
    if (count($instanceDb->rows('SELECT id FROM ' . $instanceIndex)) !== 3) { throw new RuntimeException('Repeated migration lost rows.'); }
    echo "Instance schema: legacy preservation, repeated idempotent upgrade, distinct scopes, duplicate ordinal rejection and full-depth paths verified.\n";
} finally {
    $instanceDb->execute('DROP TABLE IF EXISTS ' . $instanceIndex);
    $instanceDb->execute('DROP TABLE IF EXISTS ' . $instanceFiles);
}
