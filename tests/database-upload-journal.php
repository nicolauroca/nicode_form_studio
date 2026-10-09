<?php
declare(strict_types=1);

// Runs under database.php's isolated database guard.
$uploadClock = time();
$uploadJobs = new Nicode\FormStudio\Infrastructure\Database\JobRepository($connection);
$journal = new Nicode\FormStudio\Infrastructure\Database\UploadJournal($connection, $uploadJobs, static function () use (&$uploadClock): int { return $uploadClock; });
$uploadForm = $forms->create('Upload ownership', 'upload-' . bin2hex(random_bytes(6)), 1);
$uploadRoot = $root . '/build/upload-journal' . $suffix;
if (!is_dir($uploadRoot)) { mkdir($uploadRoot, 0700, true); }
$uploadStorage = new Nicode\FormStudio\Storage\LocalStorage($uploadRoot, $root . '/tests/http');
$uploadJobFloor = (int) ($connection->row('SELECT MAX(id) AS maximum FROM ' . $connection->table('jobs'))['maximum'] ?? 0);
$uploadKeys = [];
$uploadAssert = static function (bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } };
$uploadReject = static function (callable $operation): void {
    try { $operation(); } catch (DomainException|LogicException $expected) { return; }
    throw new RuntimeException('Unsafe upload operation accepted.');
};
$uploadStream = static function () { $stream = fopen('php://temp', 'w+b'); fwrite($stream, 'owned bytes'); rewind($stream); return $stream; };
try {
    $reservation = $journal->reserve($uploadForm, $uploadStorage); $uploadKeys[] = $reservation->key;
    $uploadAssert(!$uploadStorage->exists($reservation->key) && $connection->row('SELECT id FROM ' . $connection->table('upload_staging') . ' WHERE id = :id', [':id' => $reservation->id]) !== null, 'Ownership was not committed before bytes.');
    // A second physical connection cannot acquire purge's exclusive fence while
    // a writer holds its shared fence. Timeout is deliberately fixture-local.
    $lockProbe = new PDO(($postgres ? 'pgsql' : 'mysql') . ':host=127.0.0.1;port=' . $port . ';dbname=' . $databaseName, $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $lockProbe->exec($postgres ? "SET lock_timeout = '100ms'" : 'SET SESSION innodb_lock_wait_timeout = 1');
    $connection->transaction(function () use ($connection, $lockProbe, $uploadAssert): void {
        (new Nicode\FormStudio\Infrastructure\Database\PurgeState($connection))->shareSchema();
        $lockProbe->beginTransaction(); $blocked = false;
        try { $lockProbe->query("SELECT id FROM nfs_nicode_form_studio_installation_state WHERE state_key = 'schema' FOR UPDATE"); }
        catch (PDOException $expected) { $blocked = in_array($expected->getCode(), ['HY000', '55P03'], true); }
        finally { $lockProbe->rollBack(); }
        $uploadAssert($blocked, 'Purge crossed an active writer fence.');
    });
    $uploadReject(fn () => $connection->transaction(fn () => $journal->reserve($uploadForm, $uploadStorage)));
    $stream = $uploadStream(); $file = $journal->write($reservation, $uploadStorage, $stream, 100); fclose($stream);
    $uploadReject(fn () => $connection->transaction(fn () => $journal->consume($uploadForm + 1, $file, $reservation->token)));
    $uploadReject(fn () => $connection->transaction(fn () => $journal->consume($uploadForm, $file, str_repeat('0', 64))));
    try { $connection->transaction(function () use ($journal, $uploadForm, $file, $reservation): void { $journal->consume($uploadForm, $file, $reservation->token); throw new RuntimeException('Rollback attachment.'); }); } catch (RuntimeException $expected) { if ($expected->getMessage() !== 'Rollback attachment.') { throw $expected; } }
    $uploadAssert($connection->row('SELECT id FROM ' . $connection->table('upload_staging') . ' WHERE id = :id', [':id' => $reservation->id]) !== null, 'Rollback lost durable ownership.');
    $uploadReject(fn () => $connection->transaction(fn () => $journal->discardKey($file->provider, $file->key, $reservation->token, $uploadStorage)));
    $connection->transaction(fn () => $journal->consume($uploadForm, $file, $reservation->token));
    $journal->discardKey($file->provider, $file->key, $reservation->token, $uploadStorage);
    $uploadAssert($uploadStorage->exists($file->key), 'Post-commit cleanup deleted consumed bytes.');

    // Simulate a process dying after exclusive creation, before ready checkpoint.
    $abandoned = $journal->reserve($uploadForm, $uploadStorage); $uploadKeys[] = $abandoned->key;
    $stream = $uploadStream(); $uploadStorage->putReserved($abandoned->key, $stream, 100); fclose($stream);
    $uploadClock += 3601;
    $fresh = $journal->reserve($uploadForm, $uploadStorage); $uploadKeys[] = $fresh->key;
    try { $connection->transaction(function () use ($journal): void { $journal->reap(100); throw new RuntimeException('Rollback cleanup.'); }); } catch (RuntimeException $expected) { if ($expected->getMessage() !== 'Rollback cleanup.') { throw $expected; } }
    $uploadAssert($connection->row('SELECT id FROM ' . $connection->table('upload_staging') . ' WHERE id = :id', [':id' => $abandoned->id]) !== null, 'Cleanup rollback lost ownership.');
    $uploadAssert($journal->reap(100) === 1, 'Expired reservation was not transferred exactly once.');
    $uploadAssert($journal->reap(100) === 0, 'Cleanup retried a transferred reservation.');
    $uploadAssert($connection->row('SELECT id FROM ' . $connection->table('upload_staging') . ' WHERE id = :id', [':id' => $fresh->id]) !== null, 'Cleanup removed a fresh reservation.');
    $cleanup = $connection->row('SELECT parameters FROM ' . $connection->table('jobs') . ' WHERE id > :floor AND job_type = :type ORDER BY id', [':floor' => $uploadJobFloor, ':type' => 'file-cleanup']);
    $uploadAssert(($cleanup ? json_decode($cleanup['parameters'], true)['objects'][0]['storage_key'] : null) === $abandoned->key, 'Abandoned object lacked durable cleanup obligation.');

    // A competing exclusive create must survive collision recovery.
    $collision = $journal->reserve($uploadForm, $uploadStorage); $uploadKeys[] = $collision->key;
    $stream = $uploadStream(); $uploadStorage->putReserved($collision->key, $stream, 100); rewind($stream);
    try { $journal->write($collision, $uploadStorage, $stream, 100); throw new RuntimeException('Collision accepted.'); } catch (Nicode\FormStudio\Storage\StorageCollision) {} finally { fclose($stream); }
    $uploadAssert($uploadStorage->exists($collision->key), 'Collision recovery deleted foreign bytes.');
    $uploadAssert($connection->row('SELECT id FROM ' . $connection->table('upload_staging') . ' WHERE id = :id', [':id' => $collision->id]) === null, 'Collision retained a deletion obligation.');

    $connection->execute('UPDATE ' . $connection->table('forms') . " SET state = 'deleting' WHERE id = :id", [':id' => $uploadForm]);
    $stream = $uploadStream(); $uploadReject(fn () => $journal->write($fresh, $uploadStorage, $stream, 100)); fclose($stream);
    $uploadAssert(!$uploadStorage->exists($fresh->key), 'Deleting form accepted new bytes.');
    echo "Durable upload ownership, rollback, expiry, collision and consumed-object protection verified.\n";
} finally {
    foreach ($uploadKeys as $key) { $uploadStorage->delete($key); }
    $connection->execute('DELETE FROM ' . $connection->table('upload_staging') . ' WHERE form_id = :id', [':id' => $uploadForm]);
    foreach ($connection->rows('SELECT id FROM ' . $connection->table('jobs') . ' WHERE id > :floor AND job_type = :type', [':floor' => $uploadJobFloor, ':type' => 'file-cleanup']) as $job) { $uploadJobs->cancel((int) $job['id']); }
}
