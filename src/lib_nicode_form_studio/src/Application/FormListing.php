<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Domain\CanonicalJson;
use Nicode\FormStudio\Infrastructure\Database\Connection;
use Nicode\FormStudio\Search\CursorCodec;

final readonly class FormListing
{
    public function __construct(private Connection $db, private CursorCodec $cursors, private \Closure $authorize, private \Closure $capabilities) {}

    public function page(int $actor, array $filters = [], ?string $cursor = null, int $limit = 30): array
    {
        if (!(($this->authorize)($actor, null, 'core.manage'))) { throw new \DomainException('Administrator access denied.'); }
        if ($limit < 1 || $limit > 100 || array_diff(array_keys($filters), ['search', 'state', 'language', 'access', 'author']) !== []) { throw new \InvalidArgumentException('Invalid form filters.'); }
        $where = []; $params = [];
        foreach (['state', 'language'] as $key) {
            if (!isset($filters[$key]) || $filters[$key] === '') { continue; }
            if (!is_string($filters[$key]) || strlen($filters[$key]) > 32) { throw new \InvalidArgumentException('Invalid form filter.'); }
            $where[] = 'f.' . $this->db->quote($key) . ' = :' . $key; $params[':' . $key] = $filters[$key];
        }
        foreach (['access' => 'access', 'author' => 'created_by'] as $key => $column) {
            if (!isset($filters[$key]) || $filters[$key] === 0) { continue; }
            if (!is_int($filters[$key]) || $filters[$key] < 1) { throw new \InvalidArgumentException('Invalid form filter.'); }
            $where[] = 'f.' . $column . ' = :' . $key; $params[':' . $key] = $filters[$key];
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            if (!is_string($filters['search']) || mb_strlen($filters['search'], 'UTF-8') > 255) { throw new \InvalidArgumentException('Invalid search text.'); }
            $where[] = "(f.name LIKE :name ESCAPE '!' OR f.alias LIKE :alias ESCAPE '!')";
            $params[':name'] = $params[':alias'] = '%' . strtr($filters['search'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        $queryHash = hash('sha256', CanonicalJson::encode([$actor, $filters])); $before = PHP_INT_MAX;
        if ($cursor !== null) {
            $decoded = $this->cursors->decode($cursor);
            if (($decoded['query'] ?? '') !== $queryHash || !is_int($decoded['before'] ?? null) || $decoded['before'] < 1) { throw new \InvalidArgumentException('Cursor does not match form query.'); }
            $before = $decoded['before'];
        }
        $result = []; $hasMore = false;
        do {
            $bindings = $params + [':before' => $before];
            $rows = $this->db->rows('SELECT f.id, f.name, f.alias, f.state, f.access, f.language, f.created_by, f.modified_at, f.draft_revision, f.published_version_id, a.name AS asset_name, v.revision AS published_revision FROM ' . $this->db->table('forms') . ' f LEFT JOIN ' . $this->db->quote('#__assets') . ' a ON a.id = f.asset_id LEFT JOIN ' . $this->db->table('form_versions') . ' v ON v.id = f.published_version_id WHERE f.id < :before' . ($where ? ' AND ' . implode(' AND ', $where) : '') . ' ORDER BY f.id DESC LIMIT 100', $bindings);
            $allowed = ($this->capabilities)($actor, $rows);
            foreach ($rows as $row) {
                $before = (int) $row['id'];
                if (!isset($allowed[$before])) { continue; }
                if (count($result) === $limit) { $hasMore = true; break 2; }
                unset($row['asset_name']); $row['id'] = $before; $row['capabilities'] = $allowed[$before]; $result[] = $row;
            }
        } while (count($rows) === 100);
        $next = $hasMore ? $this->cursors->encode(['query' => $queryHash, 'before' => $result[array_key_last($result)]['id']]) : null;
        $fieldCounts = $this->counts('fields', array_column($result, 'id'));
        $responseIds = array_column(array_filter($result, static fn (array $row): bool => (bool) ($row['capabilities']['formstudio.submissions.view'] ?? false)), 'id');
        $submissionCounts = $this->counts('submissions', $responseIds);
        foreach ($result as &$row) {
            $row['field_count'] = $fieldCounts[$row['id']] ?? 0;
            $row['submission_count'] = ($row['capabilities']['formstudio.submissions.view'] ?? false) ? ($submissionCounts[$row['id']] ?? 0) : null;
        }
        unset($row);
        return ['rows' => $result, 'next_cursor' => $next];
    }

    /** Aggregate only authorized page IDs, using the form_id indexes without answer payloads. */
    private function counts(string $table, array $ids): array
    {
        if ($ids === []) { return []; }
        $parameters = [];
        foreach ($ids as $index => $id) { $parameters[':form' . $index] = $id; }
        $counts = [];
        foreach ($this->db->rows('SELECT form_id, COUNT(*) AS total FROM ' . $this->db->table($table) . ' WHERE form_id IN (' . implode(', ', array_keys($parameters)) . ') GROUP BY form_id', $parameters) as $row) {
            $counts[(int) $row['form_id']] = (int) $row['total'];
        }
        return $counts;
    }
}
