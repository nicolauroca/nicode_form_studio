<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Infrastructure\Database;

use Nicode\FormStudio\Contract\{StagedStorageProviderInterface, StorageProviderInterface};
use Nicode\FormStudio\Storage\{StoredFile, UploadReservation};

/** Durable ownership before bytes; reserve must run outside the caller's transaction. */
final readonly class UploadJournal
{
    private \Closure $clock;
    public function __construct(private Connection $db, private JobRepository $jobs, ?\Closure $clock = null) { $this->clock = $clock ?? time(...); }

    public function reserve(int $form, StagedStorageProviderInterface $storage): UploadReservation
    {
        if ($form < 1 || $this->db->inTransaction()) { throw new \LogicException('Upload reservation requires an independent durable transaction.'); }
        $key = $storage->reserveKey();
        if ($key === '' || strlen($key) > 255 || preg_match('/[\x00-\x1f\x7f]/', $key)) { throw new \DomainException('Invalid reserved object key.'); }
        if ($storage->exists($key)) { throw new \Nicode\FormStudio\Storage\StorageCollision('Storage reservation must select an unused key.'); }
        $token = bin2hex(random_bytes(32));
        $id = $this->db->transaction(function () use ($form, $storage, $key, $token): int {
            $this->guardPersistence(); $this->writableForm($form);
            if ($this->db->row('SELECT id FROM ' . $this->db->table('submission_files') . ' WHERE provider = :provider AND storage_key = :key', [':provider' => $storage->id(), ':key' => $key])) { throw new \Nicode\FormStudio\Storage\StorageCollision('Storage key is already attached.'); }
            return $this->db->insert('upload_staging', ['form_id' => $form, 'provider' => $storage->id(), 'storage_key' => $key, 'owner_token' => $token, 'state' => 'reserved', 'created_at' => $this->date(), 'expires_at' => $this->date(($this->clock)() + 3600), 'size_bytes' => null, 'checksum' => null]);
        });
        return new UploadReservation($id, $form, $storage->id(), $key, $token);
    }

    public function write(UploadReservation $reservation, StagedStorageProviderInterface $storage, mixed $stream, int $maxBytes): StoredFile
    {
        if ($this->db->inTransaction() || $reservation->provider !== $storage->id()) { throw new \LogicException('Invalid upload write context.'); }
        try {
            return $this->db->transaction(function () use ($reservation, $storage, $stream, $maxBytes): StoredFile {
                $this->guardPersistence(); $this->writableForm($reservation->form);
                $row = $this->owned($reservation->provider, $reservation->key, $reservation->token);
                if (!$row || (int) $row['id'] !== $reservation->id || (int) $row['form_id'] !== $reservation->form || $row['state'] !== 'reserved' || $row['expires_at'] <= $this->date()) { throw new \DomainException('Upload reservation expired or ownership changed.'); }
                $file = $storage->putReserved($reservation->key, $stream, $maxBytes);
                if ($file->key !== $reservation->key || $file->provider !== $reservation->provider || $file->size < 0 || $file->size > $maxBytes || preg_match('/^[a-f0-9]{64}$/D', $file->checksum) !== 1) { throw new \DomainException('Storage violated its reserved-object contract.'); }
                $this->db->execute('UPDATE ' . $this->db->table('upload_staging') . " SET state = 'ready', size_bytes = :size, checksum = :checksum, expires_at = :expires WHERE id = :id", [':size' => $file->size, ':checksum' => $file->checksum, ':expires' => $this->date(($this->clock)() + 3600), ':id' => $reservation->id]);
                return $file;
            });
        } catch (\Throwable $error) {
            if ($error instanceof \Nicode\FormStudio\Storage\StorageCollision) {
                // The exclusive create did not own any bytes; do not schedule deletion.
                $this->db->transaction(function () use ($reservation): void {
                    (new PurgeState($this->db))->shareSchema();
                    $row = $this->owned($reservation->provider, $reservation->key, $reservation->token);
                    if ($row) { $this->db->execute('DELETE FROM ' . $this->db->table('upload_staging') . ' WHERE id = :id', [':id' => (int) $row['id']]); }
                });
                throw $error;
            }
            // If cleanup itself fails, the original committed reservation remains.
            try { $this->discardKey($reservation->provider, $reservation->key, $reservation->token, $storage); } catch (\Throwable) {}
            throw $error;
        }
    }

    public function stage(int $form, StorageProviderInterface $storage, mixed $stream, int $maxBytes): array
    {
        if (!$storage instanceof StagedStorageProviderInterface) { throw new \DomainException('Storage cannot stage managed uploads safely.'); }
        $reservation = $this->reserve($form, $storage);
        return ['file' => $this->write($reservation, $storage, $stream, $maxBytes), 'staging_token' => $reservation->token];
    }

    /** Called before the form lock, inside the response transaction. */
    public function guardPersistence(): void
    {
        if (!$this->db->inTransaction()) { throw new \LogicException('Upload guard requires a transaction.'); }
        $state = new PurgeState($this->db); $state->shareSchema(); $state->assertWritable();
    }

    public function consume(int $form, StoredFile $file, mixed $token): void
    {
        if (!$this->db->inTransaction() || !is_string($token)) { throw new \DomainException('Upload ownership is missing.'); }
        $row = $this->owned($file->provider, $file->key, $token);
        if (!$row || (int) $row['form_id'] !== $form || $row['state'] !== 'ready' || $row['expires_at'] <= $this->date() || (int) $row['size_bytes'] !== $file->size || !hash_equals((string) $row['checksum'], $file->checksum)) { throw new \DomainException('Upload cannot be attached to this response.'); }
        $this->db->execute('DELETE FROM ' . $this->db->table('upload_staging') . ' WHERE id = :id', [':id' => (int) $row['id']]);
    }

    /** Missing reservation means consumed/queued: never delete the bound object. */
    public function discardKey(string $provider, string $key, string $token, ?StorageProviderInterface $storage = null): void
    {
        if ($this->db->inTransaction()) { throw new \LogicException('Upload discard requires an independent durable transaction.'); }
        $queued = $this->db->transaction(function () use ($provider, $key, $token): bool {
            (new PurgeState($this->db))->shareSchema();
            $row = $this->owned($provider, $key, $token);
            if ($row === null) { return false; }
            $this->transfer([$row]); return true;
        });
        if ($queued && $storage !== null && $storage->id() === $provider) {
            try { $storage->delete($key); } catch (\Throwable) { /* Durable outbox remains authoritative. */ }
        }
    }

    /** Bounded transfer, not physical deletion. Safe inside a worker transaction. */
    public function reap(int $limit, ?int $form = null, bool $purge = false): int
    {
        if ($limit < 1 || $limit > 500 || ($form !== null && $form < 1)) { throw new \InvalidArgumentException('Invalid staging cleanup scope.'); }
        return $this->db->transaction(function () use ($limit, $form, $purge): int {
            $state = new PurgeState($this->db); $state->shareSchema();
            if ($purge && !$state->active()) { throw new \DomainException('Unscoped staging cleanup requires active purge.'); }
            $where = []; $params = [];
            if ($form !== null) {
                $owner = $this->db->row('SELECT state FROM ' . $this->db->table('forms') . ' WHERE id = :form FOR UPDATE', [':form' => $form]);
                if ($owner && $owner['state'] !== 'deleting') { throw new \DomainException('Form staging cleanup requires confirmed deletion.'); }
                $where[] = 'form_id = :form'; $params[':form'] = $form;
            } elseif (!$purge) { $where[] = 'expires_at <= :expired'; $params[':expired'] = $this->date(); }
            $rows = $this->db->rows('SELECT id, provider, storage_key FROM ' . $this->db->table('upload_staging') . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id LIMIT ' . $limit . ' FOR UPDATE SKIP LOCKED', $params);
            $this->transfer($rows); return count($rows);
        });
    }

    private function transfer(array $rows): void
    {
        if ($rows === []) { return; }
        $this->jobs->enqueue('file-cleanup', ['objects' => array_map(static fn (array $row): array => ['provider' => $row['provider'], 'storage_key' => $row['storage_key']], $rows)], 0);
        foreach ($rows as $row) { $this->db->execute('DELETE FROM ' . $this->db->table('upload_staging') . ' WHERE id = :id', [':id' => (int) $row['id']]); }
    }
    private function owned(string $provider, string $key, string $token): ?array
    {
        $row = $this->db->row('SELECT * FROM ' . $this->db->table('upload_staging') . ' WHERE provider = :provider AND storage_key = :key FOR UPDATE', [':provider' => $provider, ':key' => $key]);
        if ($row !== null && !hash_equals($row['owner_token'], $token)) { throw new \DomainException('Upload ownership changed.'); }
        return $row;
    }
    private function writableForm(int $form): void
    {
        $row = $this->db->row('SELECT state FROM ' . $this->db->table('forms') . ' WHERE id = :id' . $this->db->sharedLock(), [':id' => $form]);
        if (!$row || $row['state'] === 'deleting') { throw new \DomainException('Form no longer accepts uploads.'); }
    }
    private function date(?int $time = null): string { return gmdate('Y-m-d H:i:s', $time ?? ($this->clock)()); }
}
