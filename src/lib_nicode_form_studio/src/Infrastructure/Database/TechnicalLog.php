<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Infrastructure\Database;

use Nicode\FormStudio\Domain\Uuid;

/** Fixed event vocabulary and typed references; no free text, payloads or secrets. */
final readonly class TechnicalLog
{
    private const EVENTS = ['submission.unexpected', 'submission.persistence_failed', 'form.render_failed', 'upload.cleanup_failed', 'action.failed', 'action.unknown', 'job.failed', 'job.retry', 'compiler.failed', 'admin.unexpected', 'storage.unavailable', 'datasource.failed', 'extension.failed', 'search.unavailable'];
    public function __construct(private Connection $db, private bool $debug = false) {}
    public function record(string $level, string $event, ?string $correlation = null, array $references = []): void
    {
        if (!in_array($level, ['ERROR', 'WARNING', 'INFO', 'DEBUG'], true) || !in_array($event, self::EVENTS, true)) { throw new \InvalidArgumentException('Unsupported technical event.'); }
        if ($level === 'DEBUG' && !$this->debug) { return; }
        $correlation ??= Uuid::create();
        if (!Uuid::valid($correlation) || array_diff(array_keys($references), ['form_uuid', 'version_id', 'submission_uuid', 'action_run_id', 'job_id']) !== []) { throw new \InvalidArgumentException('Invalid log references.'); }
        $row = ['correlation_id' => $correlation, 'level' => $level, 'event_type' => $event, 'created_at' => gmdate('Y-m-d H:i:s')];
        foreach (['form_uuid', 'submission_uuid'] as $key) {
            $value = $references[$key] ?? null;
            if ($value !== null && !Uuid::valid($value)) { throw new \InvalidArgumentException('Invalid log UUID.'); }
            $row[$key] = $value;
        }
        foreach (['version_id', 'action_run_id', 'job_id'] as $key) {
            $value = $references[$key] ?? null;
            if ($value !== null && (!is_int($value) || $value < 1)) { throw new \InvalidArgumentException('Invalid log identity.'); }
            $row[$key] = $value;
        }
        try { $this->db->insert('technical_log', $row); }
        catch (\Throwable) { /* A log sink failure cannot change a submission or side-effect outcome. */ }
    }
    public function page(int $actor, \Closure $authorize, int $before = PHP_INT_MAX, ?string $correlation = null): array
    {
        if (!$authorize($actor, null, 'core.manage') || !$authorize($actor, null, 'formstudio.logs.view')) { throw new \DomainException('Technical logs unavailable.'); }
        if ($before < 1 || ($correlation !== null && !Uuid::valid($correlation))) { throw new \InvalidArgumentException('Invalid log cursor or correlation.'); }
        $parameters = [':before' => $before];
        if ($correlation !== null) { $parameters[':correlation'] = $correlation; }
        $rows = $this->db->rows('SELECT * FROM ' . $this->db->table('technical_log') . ' WHERE id < :before' . ($correlation !== null ? ' AND correlation_id = :correlation' : '') . ' ORDER BY id DESC LIMIT 101', $parameters);
        $more = count($rows) > 100; if ($more) { array_pop($rows); }
        return ['rows' => $rows, 'next_before' => $more ? (int) end($rows)['id'] : null, 'correlation' => $correlation];
    }
}
