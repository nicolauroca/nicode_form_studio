<?php
declare(strict_types=1);

$schemaHealth = new Nicode\FormStudio\Health\SchemaColumns($connection);
if ($schemaHealth->inspect() !== ['status' => 'ok', 'count' => 0, 'more' => false]) { throw new RuntimeException('Installed schema failed column diagnostics.'); }
// Only the named isolated integration DB is used by database.php. Restore DDL
// even after failed assertions; no response rows or schema checkpoints change.
$schemaTable = $connection->table('rate_limits');
$schemaOriginal = $connection->quote('scope_hash'); $schemaRenamed = $connection->quote('health_missing_scope');
$connection->execute('ALTER TABLE ' . $schemaTable . ' RENAME COLUMN ' . $schemaOriginal . ' TO ' . $schemaRenamed);
try {
    if ($schemaHealth->inspect() !== ['status' => 'unavailable', 'count' => 1, 'more' => false]) { throw new RuntimeException('Missing required column was not diagnosed.'); }
} finally { $connection->execute('ALTER TABLE ' . $schemaTable . ' RENAME COLUMN ' . $schemaRenamed . ' TO ' . $schemaOriginal); }
$schemaTemporary = $connection->table('rate_limits_health_hold');
$connection->execute('ALTER TABLE ' . $schemaTable . ' RENAME TO ' . $schemaTemporary);
try {
    if ($schemaHealth->inspect() !== ['status' => 'unavailable', 'count' => 1, 'more' => false]) { throw new RuntimeException('Missing required table was not diagnosed.'); }
} finally { $connection->execute('ALTER TABLE ' . $schemaTemporary . ' RENAME TO ' . $schemaTable); }
if ($schemaHealth->inspect()['status'] !== 'ok') { throw new RuntimeException('Restored schema did not recover health.'); }
echo "Read-only schema diagnostics: all 30 tables, missing column/table, bounded safe failure and recovery verified.\n";
$indexHealth = new Nicode\FormStudio\Health\SchemaIndexes($driver);
if ($indexHealth->inspect() !== ['status' => 'ok', 'count' => 0, 'more' => false]) { throw new RuntimeException('Installed schema failed index diagnostics: ' . json_encode($indexHealth->inspect())); }
$healthIndex = $connection->quote(($postgres ? '#__' : '') . 'nfs_rate_limits_i0');
$dropHealthIndex = $postgres ? 'DROP INDEX ' . $healthIndex : 'DROP INDEX ' . $healthIndex . ' ON ' . $schemaTable;
$connection->execute($dropHealthIndex);
$healthEquivalentIndexes = [];
try {
    if ($postgres) {
        // Old test installations can retain equivalent pre-prefix indexes.
        $healthEquivalentIndexes = $connection->rows('SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = :table AND indexdef LIKE :definition', [':table' => $driver->replacePrefix('#__nicode_form_studio_rate_limits'), ':definition' => '% USING btree (expires_at)']);
        if ($healthEquivalentIndexes !== [] && $indexHealth->inspect()['status'] !== 'ok') { throw new RuntimeException('Equivalent index name was incorrectly rejected.'); }
        foreach ($healthEquivalentIndexes as $equivalent) { $connection->execute('DROP INDEX ' . $connection->quote($equivalent['indexname'])); }
    }
    if ($indexHealth->inspect()['count'] !== 1) { throw new RuntimeException('Missing index was not diagnosed.'); }
    // A matching name with the wrong columns must not satisfy the contract.
    $connection->execute('CREATE INDEX ' . $healthIndex . ' ON ' . $schemaTable . ' (' . $connection->quote('attempts') . ')');
    try { if ($indexHealth->inspect()['count'] !== 1) { throw new RuntimeException('Wrong index definition passed health.'); } }
    finally { $connection->execute($dropHealthIndex); }
    if ($postgres) {
        foreach ([' DESC)', ') WHERE expires_at IS NOT NULL'] as $suffix) {
            $connection->execute('CREATE INDEX ' . $healthIndex . ' ON ' . $schemaTable . ' (' . $connection->quote('expires_at') . $suffix);
            try { if ($indexHealth->inspect()['count'] !== 1) { throw new RuntimeException('Descending or partial index passed complete ascending contract.'); } }
            finally { $connection->execute($dropHealthIndex); }
        }
    }
} finally {
    $connection->execute('CREATE INDEX ' . $healthIndex . ' ON ' . $schemaTable . ' (' . $connection->quote('expires_at') . ')');
    foreach ($healthEquivalentIndexes as $equivalent) { $connection->execute('CREATE INDEX ' . $connection->quote($equivalent['indexname']) . ' ON ' . $schemaTable . ' (' . $connection->quote('expires_at') . ')'); }
}
if ($indexHealth->inspect()['status'] !== 'ok') { throw new RuntimeException('Restored index did not recover health.'); }
echo "Index diagnostics: required primary/unique/search keys, missing and same-name wrong index, and recovery verified.\n";
$typeHealth = new Nicode\FormStudio\Health\SchemaTypes($driver);
if ($typeHealth->inspect() !== ['status' => 'ok', 'count' => 0, 'more' => false]) { throw new RuntimeException('Installed schema failed type diagnostics: ' . json_encode($typeHealth->inspect())); }
$healthAttempts = $connection->quote('attempts');
$connection->execute('ALTER TABLE ' . $schemaTable . ($postgres ? ' ALTER COLUMN ' . $healthAttempts . ' DROP NOT NULL' : ' MODIFY COLUMN ' . $healthAttempts . ' BIGINT NULL'));
try { if ($typeHealth->inspect()['count'] !== 1) { throw new RuntimeException('Wrong nullability passed type health.'); } }
finally { $connection->execute('ALTER TABLE ' . $schemaTable . ($postgres ? ' ALTER COLUMN ' . $healthAttempts . ' SET NOT NULL' : ' MODIFY COLUMN ' . $healthAttempts . ' BIGINT NOT NULL')); }
// Widening to decimal preserves every existing bigint fixture value.
$connection->execute('ALTER TABLE ' . $schemaTable . ($postgres ? ' ALTER COLUMN ' . $healthAttempts . ' TYPE DECIMAL(38,0)' : ' MODIFY COLUMN ' . $healthAttempts . ' DECIMAL(38,0) NOT NULL'));
try { if ($typeHealth->inspect()['count'] !== 1) { throw new RuntimeException('Wrong numeric type passed health.'); } }
finally { $connection->execute('ALTER TABLE ' . $schemaTable . ($postgres ? ' ALTER COLUMN ' . $healthAttempts . ' TYPE BIGINT' : ' MODIFY COLUMN ' . $healthAttempts . ' BIGINT NOT NULL')); }
if ($typeHealth->inspect()['status'] !== 'ok') { throw new RuntimeException('Restored column type did not recover health.'); }
echo "Column type diagnostics: sizes, numeric/temporal precision, nullability, altered types and recovery verified.\n";
$healthScope = $connection->quote('scope_hash');
$connection->execute('ALTER TABLE ' . $schemaTable . ($postgres ? ' ALTER COLUMN ' . $healthScope . ' TYPE CHAR(64) COLLATE "C"' : ' MODIFY COLUMN ' . $healthScope . ' CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL'));
try { if ($typeHealth->inspect()['count'] !== 1) { throw new RuntimeException('Changed column collation passed health.'); } }
finally { $connection->execute('ALTER TABLE ' . $schemaTable . ($postgres ? ' ALTER COLUMN ' . $healthScope . ' TYPE CHAR(64) COLLATE "default"' : ' MODIFY COLUMN ' . $healthScope . ' CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL')); }
if ($postgres) {
    $connection->execute('ALTER TABLE ' . $schemaTable . ' ALTER COLUMN id SET GENERATED ALWAYS');
    try { if ($typeHealth->inspect()['count'] !== 1) { throw new RuntimeException('Changed identity mode passed health.'); } }
    finally { $connection->execute('ALTER TABLE ' . $schemaTable . ' ALTER COLUMN id SET GENERATED BY DEFAULT'); }
} else {
    $healthNextRow = $connection->row('SELECT AUTO_INCREMENT AS next_id FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table', [':table' => $driver->replacePrefix('#__nicode_form_studio_rate_limits')]);
    $healthNext = (int) array_values($healthNextRow)[0];
    if ($healthNext < 1) { throw new RuntimeException('Unable to preserve isolated fixture identity counter.'); }
    $connection->execute('ALTER TABLE ' . $schemaTable . ' MODIFY COLUMN id BIGINT NOT NULL');
    try { if ($typeHealth->inspect()['count'] !== 1) { throw new RuntimeException('Missing auto-increment passed health.'); } }
    finally { $connection->execute('ALTER TABLE ' . $schemaTable . ' MODIFY COLUMN id BIGINT NOT NULL AUTO_INCREMENT, AUTO_INCREMENT = ' . $healthNext); }
}
if ($typeHealth->inspect()['status'] !== 'ok') { throw new RuntimeException('Identity/collation recovery failed.'); }
echo "Storage schema diagnostics: changed collation and identity generation rejected; original metadata restored.\n";
$foreignHealth = new Nicode\FormStudio\Health\SchemaForeignKeys($driver);
if ($foreignHealth->inspect()['status'] !== 'ok') { throw new RuntimeException('Installed foreign keys failed diagnostics: ' . json_encode($foreignHealth->inspect())); }
$foreignTable = $connection->table('elements');
$foreignNames = $postgres
    ? $connection->rows("SELECT conname AS name FROM pg_constraint WHERE conrelid = to_regclass(:table) AND contype = 'f'", [':table' => $driver->replacePrefix('#__nicode_form_studio_elements')])
    : $connection->rows('SELECT CONSTRAINT_NAME AS name FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column AND REFERENCED_TABLE_NAME IS NOT NULL', [':table' => $driver->replacePrefix('#__nicode_form_studio_elements'), ':column' => 'form_id']);
if (count($foreignNames) !== 1) { throw new RuntimeException('Expected one isolated elements foreign key for mutation test.'); }
$foreignName = $connection->quote((string) array_values($foreignNames[0])[0]);
$foreignDrop = 'ALTER TABLE ' . $foreignTable . ($postgres ? ' DROP CONSTRAINT ' : ' DROP FOREIGN KEY ') . $foreignName;
$foreignCreate = 'ALTER TABLE ' . $foreignTable . ' ADD CONSTRAINT ' . $foreignName . ' FOREIGN KEY (form_id) REFERENCES ' . $connection->table('forms') . ' (id)';
$connection->execute($foreignDrop);
try {
    if ($foreignHealth->inspect()['count'] !== 1) { throw new RuntimeException('Missing foreign key passed diagnostics.'); }
    $connection->execute($foreignCreate . ' ON DELETE CASCADE');
    try { if ($foreignHealth->inspect()['count'] !== 1) { throw new RuntimeException('Cascading foreign key passed restrictive contract.'); } }
    finally { $connection->execute($foreignDrop); }
} finally { $connection->execute($foreignCreate); }
if ($foreignHealth->inspect()['status'] !== 'ok') { throw new RuntimeException('Restored foreign key did not recover health.'); }
echo "Foreign-key diagnostics: required references, missing relation, changed delete rule and restored constraint verified.\n";
