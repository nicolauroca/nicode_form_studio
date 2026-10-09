<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Domain\{CanonicalJson, Uuid};
use Nicode\FormStudio\Infrastructure\Database\Connection;
use Nicode\FormStudio\Search\SearchRequest;

/** Private presets store query intent, never an authorization grant or response snapshot. */
final readonly class SavedSubmissionViews
{
    public function __construct(private Connection $db, private SubmissionExplorer $explorer, private \Closure $authorize) {}
    public function listing(int $actor, int $before = PHP_INT_MAX): array
    {
        $this->access($actor);
        if ($before < 1) { throw new \InvalidArgumentException('Invalid view cursor.'); }
        $rows = $this->db->rows('SELECT id, uuid, name, form_id FROM ' . $this->db->table('saved_views') . ' WHERE owner_id = :actor AND shared = 0 AND id < :before ORDER BY id DESC LIMIT 101', [':actor' => $actor, ':before' => $before]);
        $more = count($rows) > 100; if ($more) { array_pop($rows); }
        return ['rows' => $rows, 'before' => $more ? (int) end($rows)['id'] : null];
    }
    public function create(int $actor, string $name, array $query): int
    {
        $this->access($actor); $name = trim($name);
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 255) { throw new \InvalidArgumentException('Invalid preset name.'); }
        $query = $this->validated($actor, $query);
        return $this->db->insert('saved_views', ['uuid' => Uuid::create(), 'owner_id' => $actor, 'form_id' => isset($query['filters']['form_id']) ? (int) $query['filters']['form_id'] : null, 'name' => $name, 'shared' => 0, 'query_spec' => CanonicalJson::encode(['filters' => $query['filters'], 'fields' => $query['fields']]), 'columns_spec' => CanonicalJson::encode($query['columns']), 'sort_spec' => CanonicalJson::encode(['order' => $query['sort'] ?? 'received_at_desc'])]);
    }
    public function read(int $actor, int $id): array
    {
        $this->access($actor);
        $row = $this->db->row('SELECT name, query_spec, columns_spec, sort_spec FROM ' . $this->db->table('saved_views') . ' WHERE id = :id AND owner_id = :actor AND shared = 0', [':id' => $id, ':actor' => $actor]) ?? throw new \OutOfBoundsException('Saved view unavailable.');
        $query = json_decode($row['query_spec'], true, 16, JSON_THROW_ON_ERROR); $query['columns'] = json_decode($row['columns_spec'], true, 16, JSON_THROW_ON_ERROR);
        $query['sort'] = json_decode($row['sort_spec'], true, 8, JSON_THROW_ON_ERROR)['order'] ?? 'received_at_desc';
        return ['id' => $id, 'name' => $row['name'], 'query' => $this->validated($actor, $query)];
    }
    public function remove(int $actor, int $id): void
    {
        $this->access($actor);
        if ($this->db->execute('DELETE FROM ' . $this->db->table('saved_views') . ' WHERE id = :id AND owner_id = :actor AND shared = 0', [':id' => $id, ':actor' => $actor]) !== 1) { throw new \OutOfBoundsException('Saved view unavailable.'); }
    }
    private function validated(int $actor, array $query): array
    {
        if (array_diff(array_keys($query), ['filters', 'fields', 'columns', 'sort']) !== [] || strlen(CanonicalJson::encode($query)) > 32768) { throw new \InvalidArgumentException('Invalid preset query.'); }
        foreach (['filters', 'fields', 'columns'] as $key) { if (!is_array($query[$key] ?? [])) { throw new \InvalidArgumentException('Invalid preset query.'); } }
        $filters = $query['filters'] ?? []; $fields = $query['fields'] ?? []; $columns = $query['columns'] ?? [];
        $sort = $query['sort'] ?? 'received_at_desc';
        if (!is_string($sort)) { throw new \InvalidArgumentException('Invalid preset order.'); }
        $this->explorer->page($actor, new SearchRequest($filters, $fields, 1, sort: $sort), $columns);
        return ['filters' => $filters, 'fields' => $fields, 'columns' => $columns] + ($sort === 'received_at_desc' ? [] : ['sort' => $sort]);
    }
    private function access(int $actor): void
    {
        if ($actor < 1 || !(($this->authorize)($actor, null, 'core.manage'))) { throw new \DomainException('Saved view access denied.'); }
    }
}
