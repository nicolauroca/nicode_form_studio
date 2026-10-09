<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Infrastructure\Joomla;

use Joomla\Registry\Registry;
use Nicode\FormStudio\Infrastructure\Database\Connection;

/** Read-only operational probes. Never return configuration paths or exception text. */
final readonly class SystemHealth
{
    public function __construct(private Connection $db, private Registry $config, private string $publicRoot, private \Closure $authorize, private array $probes = []) {}
    public function report(int $actor): array
    {
        if (!(($this->authorize)($actor, null, 'core.manage')) || !(($this->authorize)($actor, null, 'formstudio.logs.view'))) { throw new \DomainException('System diagnostics unavailable.'); }
        $checks = [];
        foreach (['storage_path', 'export_path'] as $key) {
            $path = (string) $this->config->get($key, '');
            $status = $path === '' ? 'not_configured' : 'ok';
            if ($path !== '') {
                try {
                    if ($key === 'storage_path') { new \Nicode\FormStudio\Storage\LocalStorage($path, $this->publicRoot); }
                    else { new \Nicode\FormStudio\Export\ExportWorkspace($path, $this->publicRoot); }
                    $free = @disk_free_space($path);
                    if ($free !== false && $free < 104857600) { $status = 'low_space'; }
                } catch (\Throwable) { $status = 'unavailable'; }
            }
            $checks[$key] = ['status' => $status];
        }
        $checks['database'] = $this->probe(fn () => $this->db->row('SELECT 1 AS ready') !== null);
        $checks['schema_columns'] = (new \Nicode\FormStudio\Health\SchemaColumns($this->db))->inspect();
        foreach ($this->probes as $name => $probe) {
            try { $checks[$name] = $probe(); }
            catch (\Throwable) { $checks[$name] = ['status' => 'unavailable']; }
        }
        $checks['schema'] = $this->probe(function (): bool {
            $state = $this->db->row('SELECT state_json FROM ' . $this->db->table('installation_state') . " WHERE state_key = 'schema'");
            $registered = $this->db->row('SELECT s.version_id FROM ' . $this->db->quote('#__schemas') . ' s JOIN ' . $this->db->quote('#__extensions') . " e ON e.extension_id = s.extension_id WHERE e.type = 'component' AND e.element = 'com_nicode_form_studio'");
            return $state !== null && $registered !== null && (json_decode($state['state_json'], true, 16, JSON_THROW_ON_ERROR)['version'] ?? null) === $registered['version_id'];
        });
        $checks['scheduled_task'] = $this->probe(function (): bool {
            $rows = $this->db->rows('SELECT execution_rules FROM ' . $this->db->quote('#__scheduler_tasks') . " WHERE type = 'nicode.formstudio.jobs' AND state = 1 LIMIT 100");
            foreach ($rows as $row) {
                $rule = json_decode($row['execution_rules'], true, 16, JSON_THROW_ON_ERROR)['rule-type'] ?? null;
                if (is_string($rule) && $rule !== '' && $rule !== 'manual') { return true; }
            }
            return false;
        });
        $checks['scheduler'] = $this->probe(function (): bool {
            $plugin = $this->db->row('SELECT enabled FROM ' . $this->db->quote('#__extensions') . " WHERE type = 'plugin' AND folder = 'task' AND element = 'nicode_form_studio'");
            return $plugin !== null && (int) $plugin['enabled'] === 1;
        });
        foreach (['pending', 'retryable', 'failed'] as $state) {
            $checks['jobs_' . $state] = $this->sample('jobs', 'state = :state', [':state' => $state]);
        }
        $checks['jobs_stalled'] = $this->sample('jobs', 'state = :state AND lease_until <= :now', [':state' => 'running', ':now' => gmdate('Y-m-d H:i:s')]);
        $checks['index_backlog'] = $this->sample('submissions', 'index_pending = :pending', [':pending' => 1]);
        $checks['failed_actions'] = $this->sample('action_runs', 'state = :state', [':state' => 'failed']);
        $checks['uploads_expired'] = $this->sample('upload_staging', 'expires_at <= :now', [':now' => gmdate('Y-m-d H:i:s')]);
        foreach (['retention-dispatch', 'export-cleanup', 'technical-log-cleanup', 'upload-cleanup'] as $type) {
            try {
                $last = $this->db->row('SELECT state, created_at, finished_at FROM ' . $this->db->table('jobs') . ' WHERE job_type = :type ORDER BY id DESC LIMIT 1', [':type' => $type]);
                $checks[$type] = ['status' => $last === null ? 'not_run' : (in_array($last['state'], ['failed', 'cancelled'], true) || strtotime($last['created_at'] . ' UTC') < time() - 86400 ? 'warning' : 'ok'), 'last' => $last];
            } catch (\Throwable) { $checks[$type] = ['status' => 'unavailable']; }
        }
        $versions = [];
        try {
            $extensions = $this->db->rows('SELECT type, folder, enabled, element, manifest_cache FROM ' . $this->db->quote('#__extensions') . " WHERE (type = 'package' AND element = 'pkg_nicode_form_studio') OR (type = 'component' AND element = 'com_nicode_form_studio') OR (type = 'module' AND element = 'mod_nicode_form_studio') OR (type = 'library' AND element = 'nicode_form_studio') OR (type = 'plugin' AND folder IN ('task', 'extension') AND element = 'nicode_form_studio')");
            $checks['configuration_audit'] = ['status' => 'warning'];
            foreach ($extensions as $entry) {
                $version = json_decode($entry['manifest_cache'], true, 32, JSON_THROW_ON_ERROR)['version'] ?? '';
                $versions[$entry['type'] . ':' . ($entry['type'] === 'plugin' ? $entry['folder'] . ':' : '') . $entry['element']] = is_string($version) && preg_match('/^[0-9][0-9A-Za-z.+-]{0,40}$/D', $version) ? $version : '?';
                if ($entry['type'] === 'plugin' && $entry['folder'] === 'extension') { $checks['configuration_audit'] = ['status' => (int) $entry['enabled'] === 1 ? 'ok' : 'warning']; }
            }
            $checks['package'] = ['status' => count($versions) === 6 && !in_array('?', $versions, true) && count(array_unique($versions)) === 1 ? 'ok' : 'warning'];
        } catch (\Throwable) { $checks['package'] = ['status' => 'unavailable']; }
        $limits = [];
        // Read only this allowlist: ini_get_all() could expose paths or credentials.
        foreach (['post_max_size', 'upload_max_filesize', 'max_file_uploads', 'max_input_vars', 'max_input_nesting_level', 'max_multipart_body_parts', 'memory_limit', 'max_input_time', 'max_execution_time'] as $directive) {
            $value = ini_get($directive);
            $limits[$directive] = is_string($value) && preg_match('/^-?[0-9]+[KMG]?$/iD', $value) ? $value : null;
        }
        $limits['file_uploads'] = filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        return ['php' => PHP_VERSION, 'joomla' => JVERSION, 'formspec' => '1.0', 'checks' => $checks, 'versions' => $versions, 'php_limits' => $limits];
    }
    private function probe(\Closure $probe): array
    {
        try { return ['status' => $probe() ? 'ok' : 'not_configured']; }
        catch (\Throwable) { return ['status' => 'unavailable']; }
    }
    private function sample(string $table, string $where, array $parameters): array
    {
        try {
            $rows = $this->db->rows('SELECT id FROM ' . $this->db->table($table) . ' WHERE ' . $where . ' LIMIT 101', $parameters);
            return ['status' => $rows === [] ? 'ok' : 'warning', 'count' => min(count($rows), 100), 'more' => count($rows) > 100];
        } catch (\Throwable) { return ['status' => 'unavailable']; }
    }
}
