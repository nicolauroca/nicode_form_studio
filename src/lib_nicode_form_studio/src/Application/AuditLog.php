<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Domain\Uuid;
use Nicode\FormStudio\Infrastructure\Database\Connection;

/** Administrative event headers only; no answer payload or free-form metadata projection. */
final readonly class AuditLog
{
    public function __construct(private Connection $db, private \Closure $authorize) {}

    public function page(int $actor, array $filters = [], int $before = PHP_INT_MAX): array
    {
        if (!(($this->authorize)($actor, null, 'core.manage')) || !(($this->authorize)($actor, null, 'formstudio.logs.view'))) { throw new \DomainException('Audit log unavailable.'); }
        if ($before < 1 || array_diff(array_keys($filters), ['correlation_id', 'form_id', 'actor_id', 'submission_uuid', 'event_type', 'from', 'to']) !== []) { throw new \InvalidArgumentException('Invalid audit filters.'); }
        $where = ['id < :before']; $parameters = [':before' => $before];
        foreach (['correlation_id', 'submission_uuid', 'event_type', 'form_id', 'actor_id'] as $key) {
            if (!array_key_exists($key, $filters)) { continue; }
            $value = $filters[$key];
            $valid = match ($key) {
                'correlation_id', 'submission_uuid' => Uuid::valid($value),
                'form_id', 'actor_id' => is_int($value) && $value > 0,
                'event_type' => is_string($value) && preg_match('/^[a-z][a-z0-9_.]{0,63}$/D', $value) === 1,
            };
            if (!$valid) { throw new \InvalidArgumentException('Invalid audit filter value.'); }
            $where[] = $key . ' = :' . $key; $parameters[':' . $key] = $value;
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (!array_key_exists($key, $filters)) { continue; }
            $value = $filters[$key];
            $date = is_string($value) && preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $value) === 1 ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC')) : false;
            if (!$date || $date->format('Y-m-d') !== $value) { throw new \InvalidArgumentException('Invalid audit date.'); }
            $parameters[':' . $key] = $value . ($key === 'to' ? ' 23:59:59.999999' : ' 00:00:00');
            $where[] = 'created_at ' . $operator . ' :' . $key;
        }
        if (isset($filters['from'], $filters['to']) && $filters['from'] > $filters['to']) { throw new \InvalidArgumentException('Reversed audit date range.'); }
        $rows = $this->db->rows('SELECT id, correlation_id, actor_id, event_type, form_id, submission_uuid, created_at, safe_metadata FROM ' . $this->db->table('audit_log') . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 101', $parameters);
        $more = count($rows) > 100; if ($more) { array_pop($rows); }
        foreach ($rows as &$row) {
            $row['details'] = self::details($row['safe_metadata']); unset($row['safe_metadata']);
        }
        unset($row);
        return ['rows' => $rows, 'filters' => $filters, 'next_before' => $more ? (int) end($rows)['id'] : null];
    }

    public static function details(string $json): array
    {
        if (strlen($json) > 32768) { return []; }
        try { $values = json_decode($json, true, 32, JSON_THROW_ON_ERROR); } catch (\JsonException) { return []; }
        if (!is_array($values)) { return []; }
        $result = [];
        foreach (['revision', 'version_id', 'group_id', 'resource_id', 'job_id', 'rows', 'fields', 'request_metadata_items', 'files_queued', 'source_version_id'] as $key) {
            if (is_int($values[$key] ?? null) && $values[$key] >= 0) { $result[$key] = $values[$key]; }
        }
        if (in_array($values['state'] ?? null, ['draft', 'published', 'unpublished', 'trashed', ...SubmissionAdministration::STATES], true)) { $result['state'] = $values['state']; }
        foreach (['from', 'to'] as $key) {
            if (in_array($values[$key] ?? null, SubmissionAdministration::STATES, true)) { $result[$key] = $values[$key]; }
        }
        return $result;
    }
}
