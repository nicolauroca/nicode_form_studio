<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Infrastructure\Joomla;
use Nicode\FormStudio\Infrastructure\Database\{Connection, JobRepository};

/** A scheduler invocation queues at most one hourly export cleanup. */
final readonly class JobMaintenance
{
    private \Closure $clock;
    public function __construct(private Connection $db, private JobRepository $jobs, ?\Closure $clock = null) { $this->clock = $clock ?? time(...); }
    public function queueExportCleanup(): ?int
    {
        return $this->queue('export-cleanup');
    }
    public function queueRetention(): ?int { return $this->queue('retention-dispatch'); }
    public function queueUploadCleanup(): ?int { return $this->queue('upload-cleanup'); }
    public function queueRateLimitCleanup(): ?int { return $this->queue('rate-limit-cleanup'); }
    public function queueAttemptCleanup(): ?int { return $this->queue('attempt-cleanup'); }
    public function queueHistoryCleanup(string $kind, int $days): ?int
    {
        if (!in_array($kind, ['audit', 'action'], true) || $days < 0 || $days > 3650) { throw new \InvalidArgumentException('Invalid history retention.'); }
        return $days === 0 ? null : $this->queue($kind . '-history-cleanup', ['days' => $days]);
    }
    public function queueTechnicalLogCleanup(int $days): ?int
    {
        if ($days < 1 || $days > 3650) { throw new \InvalidArgumentException('Invalid log retention.'); }
        return $this->queue('technical-log-cleanup', ['days' => $days]);
    }
    private function queue(string $type, array $parameters = []): ?int
    {
        if ((new \Nicode\FormStudio\Infrastructure\Database\PurgeState($this->db))->active()) { return null; }
        return $this->db->transaction(function () use ($type, $parameters): ?int {
            // Serialize only queue creation across concurrent scheduler processes.
            $asset = $this->db->row('SELECT id FROM ' . $this->db->quote('#__assets') . ' WHERE name = :name FOR UPDATE', [':name' => 'com_nicode_form_studio']);
            if ($asset === null) { throw new \DomainException('Component asset unavailable.'); }
            $last = $this->db->row('SELECT state, created_at FROM ' . $this->db->table('jobs') . ' WHERE job_type = :type ORDER BY id DESC LIMIT 1', [':type' => $type]);
            if ($last !== null && (in_array($last['state'], ['pending', 'running', 'retryable'], true) || strtotime($last['created_at'] . ' UTC') > ($this->clock)() - 3600)) { return null; }
            return $this->jobs->enqueue($type, $parameters, 0);
        });
    }
}
