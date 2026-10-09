<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Infrastructure\Database\Connection;

/** Metadata aggregates only. No answer, filename, secret or action configuration leaves this service. */
final readonly class OperationsDashboard
{
    public function __construct(private Connection $db, private \Closure $authorize, private \Closure $formCapabilities, private \Closure $submissionCapabilities, private \Closure $diagnose, private \Closure $clock, private ?\Closure $health = null) {}

    public function report(int $actor): array
    {
        if (!(($this->authorize)($actor, null, 'core.manage'))) { throw new \DomainException('Dashboard access denied.'); }
        $now = ($this->clock)();
        $metrics = array_fill_keys(['forms_published', 'forms_unpublished', 'forms_draft', 'forms_archived', 'forms_trashed', 'forms_deleting', 'responses_7', 'responses_30', 'spam', 'response_errors', 'actions_failed', 'emails_failed', 'webhooks_failed', 'storage_bytes', 'index_pending', 'forms_invalid', 'forms_warning', 'forms_action', 'forms_captcha', 'forms_provider', 'forms_unavailable'], 0);
        $recent = []; $modified = []; $alerts = []; $after = 0; $readable = 0; $editable = 0;
        $high = (int) ($this->db->row('SELECT MAX(id) AS high_id FROM ' . $this->db->table('forms'))['high_id'] ?? 0);
        do {
            $rows = $this->db->rows('SELECT f.id, f.name, f.state, f.modified_at, f.published_version_id, a.name AS asset_name FROM ' . $this->db->table('forms') . ' f LEFT JOIN ' . $this->db->quote('#__assets') . ' a ON a.id = f.asset_id WHERE f.id > :after AND f.id <= :high ORDER BY f.id LIMIT 100', [':after' => $after, ':high' => $high]);
            $managed = ($this->formCapabilities)($actor, $rows); $read = ($this->submissionCapabilities)($actor, $rows);
            $ids = []; $names = [];
            foreach ($rows as $row) {
                $after = (int) $row['id']; $row['id'] = $after; unset($row['asset_name']);
                if (isset($managed[$after])) {
                    $key = 'forms_' . $row['state']; if (array_key_exists($key, $metrics)) { $metrics[$key]++; }
                    $row['can_edit'] = (bool) ($managed[$after]['core.edit'] ?? false);
                    $modified[] = $row;
                    if ($row['can_edit'] && $row['state'] !== 'deleting') {
                        $editable++;
                        $flags = ($this->diagnose)($after, $row['published_version_id'] === null ? null : (int) $row['published_version_id']);
                        foreach (['invalid', 'warning', 'action', 'captcha', 'provider', 'unavailable'] as $flag) {
                            if ($flags[$flag] ?? false) {
                                $metrics['forms_' . $flag]++;
                                if (count($alerts) < 10) { $alerts[] = ['form_id' => $after, 'name' => $row['name'], 'kind' => $flag]; }
                            }
                        }
                    }
                }
                if (isset($read[$after])) { $ids[] = $after; $names[$after] = $row['name']; $readable++; }
            }
            $this->latest($modified, 'modified_at');
            if ($ids !== []) { $this->responses($ids, $names, $now, $metrics, $recent); }
        } while (count($rows) === 100);
        $manager = (bool) ($this->authorize)($actor, null, 'formstudio.jobs.manage');
        $jobs = $this->db->rows('SELECT state, job_type, COUNT(*) AS total, SUM(CASE WHEN state = \'running\' AND (lease_until IS NULL OR lease_until <= :now) THEN 1 ELSE 0 END) AS stalled FROM ' . $this->db->table('jobs') . " WHERE state IN ('pending', 'running', 'retryable', 'failed')" . ($manager ? '' : ' AND creator_id = :actor') . ' GROUP BY state, job_type', [':now' => gmdate('Y-m-d H:i:s', $now)] + ($manager ? [] : [':actor' => $actor]));
        $jobCounts = ['pending' => 0, 'running' => 0, 'retryable' => 0, 'failed' => 0, 'stalled' => 0, 'exports' => 0, 'cleanup' => 0];
        foreach ($jobs as $job) {
            $total = (int) $job['total']; $jobCounts[$job['state']] += $total;
            $jobCounts['stalled'] += (int) $job['stalled'];
            if (in_array($job['job_type'], ['export-csv', 'export-json'], true) && $job['state'] !== 'failed') { $jobCounts['exports'] += $total; }
            if ($job['job_type'] === 'file-cleanup') { $jobCounts['cleanup'] += $total; }
        }
        $errors = null; $sources = null; $repeated = []; $health = [];
        if (($this->authorize)($actor, null, 'formstudio.logs.view')) {
            $errors = (int) $this->db->row('SELECT COUNT(*) AS total FROM ' . $this->db->table('technical_log') . " WHERE level = 'ERROR' AND created_at >= :from AND created_at <= :to", [':from' => gmdate('Y-m-d H:i:s', $now - 604800), ':to' => gmdate('Y-m-d H:i:s', $now)])['total'];
            $repeated = $this->db->rows('SELECT event_type, COUNT(*) AS total FROM ' . $this->db->table('technical_log') . " WHERE level = 'ERROR' AND created_at >= :from AND created_at <= :to GROUP BY event_type HAVING COUNT(*) >= 3 ORDER BY total DESC, event_type LIMIT 10", [':from' => gmdate('Y-m-d H:i:s', $now - 604800), ':to' => gmdate('Y-m-d H:i:s', $now)]);
            if ($this->health !== null) {
                try {
                    $checks = ($this->health)($actor);
                    foreach (['storage_path', 'export_path', 'database', 'schema', 'scheduled_task', 'scheduler', 'mail', 'captcha', 'search', 'cache', 'package', 'retention-dispatch', 'export-cleanup', 'technical-log-cleanup', 'upload-cleanup', 'uploads_expired'] as $name) {
                        if (isset($checks[$name]) && ($checks[$name]['status'] ?? '') !== 'ok') { $health[$name] = in_array($checks[$name]['status'] ?? '', ['not_configured', 'not_run', 'warning', 'low_space', 'unavailable'], true) ? $checks[$name]['status'] : 'unavailable'; }
                    }
                } catch (\Throwable) { $health['diagnostics'] = 'unavailable'; }
            }
        }
        if (($this->authorize)($actor, null, 'formstudio.resources.manage')) {
            $sources = (int) $this->db->row('SELECT COUNT(*) AS total FROM ' . $this->db->table('data_sources') . ' WHERE enabled = 0')['total'];
        }
        return ['metrics' => $metrics, 'recent' => $recent, 'modified' => $modified, 'alerts' => $alerts, 'jobs' => $jobCounts, 'technical_errors' => $errors, 'repeated_errors' => $repeated, 'health_alerts' => $health, 'disabled_sources' => $sources, 'readable_forms' => $readable, 'diagnosed_forms' => $editable, 'observed_at' => gmdate('Y-m-d H:i:s', $now)];
    }

    private function responses(array $ids, array $names, int $now, array &$metrics, array &$recent): void
    {
        $params = []; foreach ($ids as $i => $id) { $params[':form' . $i] = $id; }
        $scope = 's.form_id IN (' . implode(', ', array_keys($params)) . ')';
        // Separate selective counts can use the existing date/state/backlog
        // indexes. A single conditional SUM would visit every full response row.
        $counts = [
            'responses_7' => ['s.received_at >= :from AND s.received_at <= :until', [':from' => gmdate('Y-m-d H:i:s', $now - 604800), ':until' => gmdate('Y-m-d H:i:s', $now)]],
            'responses_30' => ['s.received_at >= :from AND s.received_at <= :until', [':from' => gmdate('Y-m-d H:i:s', $now - 2592000), ':until' => gmdate('Y-m-d H:i:s', $now)]],
            'spam' => ["s.state = 'spam'", []],
            'response_errors' => ["s.action_status IN ('partial_failure', 'blocking_failure')", []],
            'index_pending' => ['s.index_pending = 1', []],
        ];
        foreach ($counts as $key => [$where, $bindings]) {
            $metrics[$key] += (int) $this->db->row('SELECT COUNT(*) AS total FROM ' . $this->db->table('submissions') . ' s WHERE ' . $scope . ' AND ' . $where, $params + $bindings)['total'];
        }
        $row = $this->db->row('SELECT COUNT(*) AS actions_failed, SUM(CASE WHEN r.action_type IN (\'email_notification\', \'email_autoresponse\') THEN 1 ELSE 0 END) AS emails_failed, SUM(CASE WHEN r.action_type = \'webhook\' THEN 1 ELSE 0 END) AS webhooks_failed FROM ' . $this->db->table('action_runs') . ' r JOIN ' . $this->db->table('submissions') . " s ON s.id = r.submission_id WHERE r.state = 'failed' AND " . $scope, $params);
        foreach ($row as $key => $value) { $metrics[$key] += (int) $value; }
        $metrics['storage_bytes'] += (int) $this->db->row('SELECT SUM(f.size_bytes) AS total FROM ' . $this->db->table('submission_files') . ' f JOIN ' . $this->db->table('submissions') . ' s ON s.id = f.submission_id WHERE ' . $scope, $params)['total'];
        // Joining the small forms table here can make an optimizer sort every
        // response before LIMIT. Names are already present in the trusted ACL batch.
        foreach ($this->db->rows('SELECT s.id, s.form_id, s.state, s.received_at FROM ' . $this->db->table('submissions') . ' s WHERE ' . $scope . ' ORDER BY s.received_at DESC, s.id DESC LIMIT 10', $params) as $row) {
            $row['name'] = $names[(int) $row['form_id']]; $recent[] = $row;
        }
        $this->latest($recent, 'received_at');
    }

    private function latest(array &$rows, string $date): void
    {
        usort($rows, static fn (array $a, array $b): int => strcmp($b[$date], $a[$date]) ?: (int) $b['id'] <=> (int) $a['id']);
        $rows = array_slice($rows, 0, 10);
    }
}
