<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Infrastructure\Database;

final readonly class PurgeState
{
    public function __construct(private Connection $db) {}
    public function read(): ?array
    {
        $row = $this->db->row('SELECT state_json FROM ' . $this->db->table('installation_state') . ' WHERE state_key = :key', [':key' => 'purge']);
        return $row ? json_decode($row['state_json'], true, 32, JSON_THROW_ON_ERROR) : null;
    }
    public function active(): bool { return $this->read() !== null; }
    public function assertWritable(): void { if ($this->active()) { throw new \DomainException('Package purge is in progress.'); } }
    /** Serialize rare form creation / purge confirmation, never every submission. */
    public function lockSchema(): void
    {
        $this->db->row('SELECT id FROM ' . $this->db->table('installation_state') . ' WHERE state_key = :key FOR UPDATE', [':key' => 'schema']);
    }
    public function shareSchema(): void
    {
        $row = $this->db->row('SELECT id FROM ' . $this->db->table('installation_state') . ' WHERE state_key = :key' . $this->db->sharedLock(), [':key' => 'schema']);
        if ($row === null) { throw new \DomainException('Installed schema checkpoint is unavailable.'); }
    }
}
