<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Search;

use Nicode\FormStudio\Contract\SearchProviderInterface;
use Nicode\FormStudio\Domain\CanonicalJson;
use Nicode\FormStudio\Domain\FormSpec;
use Nicode\FormStudio\Infrastructure\Database\Connection;
use Nicode\FormStudio\Registry\FieldTypeRegistry;

final readonly class SqlSearchProvider implements SearchProviderInterface
{
    public function __construct(private Connection $db, private FieldTypeRegistry $fields, private CursorCodec $cursors) {}
    public function id(): string { return 'sql'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array
    {
        $operators = []; foreach (['keyword', 'text', 'integer', 'decimal', 'date', 'datetime', 'boolean'] as $type) { $operators[$type] = self::operators($type); }
        return ['id' => $this->id(), 'version' => $this->version(), 'keyset' => true, 'historical_privacy' => true, 'high_water' => true, 'same_instance' => true, 'exact_count' => false, 'global_fulltext' => false, 'sorts' => SearchRequest::SORTS, 'filter_operators' => $operators];
    }
    public function validateConfiguration(array $configuration, string $path): array { return []; }
    public static function operators(string $type): array
    {
        return ['equals', 'not_equals', ...match ($type) {
            'keyword', 'text' => ['contains', 'starts_with'],
            'integer', 'decimal' => ['greater', 'less', 'greater_equal', 'less_equal', 'between'],
            'date', 'datetime' => ['before', 'after', 'greater_equal', 'less_equal', 'between'],
            default => [],
        }];
    }

    public function search(SearchRequest $request, SearchScope $scope, ?FormSpec $selectedForm = null): SearchPage
    {
        if ($scope->forms === []) { return new SearchPage([], null); }
        [$sql, $parameters, $fingerprint] = $this->plan($request, $scope, $selectedForm);
        $rows = $this->db->rows($sql, $parameters);
        $hasMore = count($rows) > $request->limit;
        if ($hasMore) { array_pop($rows); }
        $last = end($rows);
        $cursor = $hasMore ? $this->cursors->encode(['query' => $fingerprint, 'received_at' => $last['received_at'], 'id' => (int) $last['id']]) : null;
        return new SearchPage($rows, $cursor);
    }

    /** Internal explain/benchmark boundary; all values remain bound. */
    public function plan(SearchRequest $request, SearchScope $scope, ?FormSpec $selectedForm = null): array
    {
        $parameters = []; $where = [];
        foreach (array_keys($scope->forms) as $i => $form) { $parameters[':scope' . $i] = $form; }
        $where[] = $parameters === [] ? '1 = 0' : 's.form_id IN (' . implode(', ', array_keys($parameters)) . ')';
        if ($request->highId !== null) { $where[] = 's.id <= :window_high'; $parameters[':window_high'] = $request->highId; }
        foreach ($request->filters as $key => $value) {
            if (!is_string($value) && !is_int($value)) { throw new \InvalidArgumentException('Invalid submission filter value.'); }
            $column = match ($key) { 'received_from', 'received_to' => 'received_at', default => $key };
            $operator = match ($key) { 'received_from' => '>=', 'received_to' => '<=', default => '=' };
            $where[] = 's.' . $this->db->quote($column) . ' ' . $operator . ' :filter_' . $key;
            $parameters[':filter_' . $key] = $value;
        }
        if ($request->fieldFilters !== []) {
            // An incomplete projection must not masquerade as a negative match.
            $where[] = 's.index_pending = 0';
            $formId = (int) ($request->filters['form_id'] ?? 0);
            if ($selectedForm === null || $formId < 1 || !array_key_exists($formId, $scope->forms)) { throw new \InvalidArgumentException('Field filters require an authorized selected form.'); }
            $owner = $this->db->row('SELECT uuid FROM ' . $this->db->table('forms') . ' WHERE id = :id', [':id' => $formId]);
            if (!$owner || $owner['uuid'] !== $selectedForm->toArray()['uuid']) { throw new \DomainException('Search schema belongs to another form.'); }
            $fieldMap = array_column($selectedForm->toArray()['fields'], null, 'uuid');
            $elements = array_column($selectedForm->toArray()['elements'], null, 'uuid'); $correlations = [];
            foreach ($request->fieldFilters as $i => $filter) {
                if (!is_array($filter) || !is_string($filter['field'] ?? null) || !isset($fieldMap[$filter['field']])) { throw new \InvalidArgumentException('Unknown filter field.'); }
                $field = $fieldMap[$filter['field']];
                if (!($field['index'] ?? false) || (($field['sensitive'] ?? false) && !$scope->forms[$formId])) { throw new \DomainException('Field filter is not authorized or indexed.'); }
                $provider = $this->fields->get($field['type']); $type = $provider->indexType();
                if ($type === null) { throw new \InvalidArgumentException('Unsupported search type.'); }
                $operator = $filter['operator'] ?? '';
                $sqlOperator = match ($operator) { 'equals', 'not_equals' => '=', 'greater', 'after' => '>', 'less', 'before' => '<', 'greater_equal' => '>=', 'less_equal' => '<=', 'contains', 'starts_with' => 'LIKE', 'between' => 'BETWEEN', default => throw new \InvalidArgumentException('Unsupported field filter operator.') };
                if (in_array($operator, ['contains', 'starts_with'], true) && !in_array($type, ['keyword', 'text'], true)) { throw new \InvalidArgumentException('Text operator requires a text field.'); }
                if (in_array($operator, ['greater', 'less', 'greater_equal', 'less_equal', 'between', 'before', 'after'], true) && !in_array($type, ['integer', 'decimal', 'date', 'datetime'], true)) { throw new \InvalidArgumentException('Ordered comparison requires numeric or date index.'); }
                $prefix = ':field' . $i;
                $parameters[$prefix . '_uuid'] = $field['uuid']; $parameters[$prefix . '_type'] = $type;
                $parameters[$prefix . '_policy_uuid'] = $field['uuid'];
                // A public current field must not disclose sensitive historical answers,
                // including through negative comparisons or absence of an index value.
                $where[] = '(SELECT p.sensitive FROM ' . $this->db->table('version_field_policy') . ' p WHERE p.form_id = s.form_id AND p.form_version_id = s.form_version_id AND p.field_uuid = ' . $prefix . '_policy_uuid AND p.indexed = 1) <= ' . ($scope->forms[$formId] ? '1' : '0');
                $column = 'x.' . $this->db->quote('value_' . $type);
                if ($type === 'text' && $sqlOperator === '=' && !$this->db->isPostgresql()) { $column = 'CAST(' . $column . ' AS BINARY)'; }
                $value = $filter['value'] ?? null;
                if ($operator === 'between') {
                    if (!is_array($value) || !array_is_list($value) || count($value) !== 2) { throw new \InvalidArgumentException('Between requires two bounds.'); }
                    $parameters[$prefix . '_value'] = $this->filterValue($type, $value[0]); $parameters[$prefix . '_end'] = $this->filterValue($type, $value[1]);
                    $comparison = "$column BETWEEN {$prefix}_value AND {$prefix}_end";
                } else {
                    $value = $this->filterValue($type, $value);
                    if ($sqlOperator === 'LIKE') { $value = ($operator === 'contains' ? '%' : '') . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $value) . '%'; }
                    $parameters[$prefix . '_value'] = $value;
                    $comparison = "$column $sqlOperator {$prefix}_value" . ($sqlOperator === 'LIKE' ? " ESCAPE '!'" : '');
                }
                $body = 'x.submission_id = s.id AND x.form_id = s.form_id AND x.field_uuid = ' . $prefix . '_uuid AND x.value_type = ' . $prefix . '_type AND ' . $comparison;
                if (array_key_exists('same_instance', $filter)) {
                    $group = $filter['same_instance'];
                    if (!is_string($group) || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $group) !== 1) { throw new \InvalidArgumentException('Invalid instance correlation name.'); }
                    $ancestors = self::repeatedScope($field['uuid'], $elements);
                    if ($ancestors === [] || (isset($correlations[$group]) && $correlations[$group]['scope'] !== $ancestors)) { throw new \InvalidArgumentException('Correlated fields must share one repeated definition scope.'); }
                    $correlations[$group]['scope'] = $ancestors;
                    $correlations[$group]['filters'][] = ['body' => $body, 'negative' => $operator === 'not_equals'];
                } else { $where[] = ($operator === 'not_equals' ? 'NOT ' : '') . 'EXISTS (SELECT 1 FROM ' . $this->db->table('submission_index') . ' x WHERE ' . $body . ')'; }
            }
            foreach (array_values($correlations) as $groupIndex => $group) {
                $anchor = null;
                foreach ($group['filters'] as $i => $filter) { if (!$filter['negative']) { $anchor = $i; break; } }
                if ($anchor === null) { throw new \InvalidArgumentException('Instance correlation requires a positive indexed predicate.'); }
                $conditions = [str_replace('x.', 'a.', $group['filters'][$anchor]['body'])];
                $scopeParameter = ':instance_scope' . $groupIndex;
                $parameters[$scopeParameter] = implode('/', array_map(static fn (string $uuid): string => $uuid . '/' . str_repeat('_', 36), $group['scope']));
                $conditions[] = 'a.instance_path LIKE ' . $scopeParameter;
                foreach ($group['filters'] as $i => $filter) {
                    if ($i === $anchor) { continue; }
                    $conditions[] = ($filter['negative'] ? 'NOT ' : '') . 'EXISTS (SELECT 1 FROM ' . $this->db->table('submission_index') . ' x WHERE ' . $filter['body'] . ' AND x.instance_hash = a.instance_hash AND x.instance_path = a.instance_path)';
                }
                $where[] = 'EXISTS (SELECT 1 FROM ' . $this->db->table('submission_index') . ' a WHERE ' . implode(' AND ', $conditions) . ')';
            }
        }
        $identity = [$request->filters, $request->fieldFilters, $scope->forms, $selectedForm?->hash, $request->highId];
        if ($request->sort !== 'received_at_desc') { $identity[] = $request->sort; }
        $fingerprint = hash('sha256', CanonicalJson::encode($identity));
        $comparison = $request->ascending() ? '>' : '<'; $direction = $request->ascending() ? 'ASC' : 'DESC';
        if ($request->cursor !== null) {
            $cursor = $this->cursors->decode($request->cursor);
            if (($cursor['query'] ?? null) !== $fingerprint || !is_int($cursor['id'] ?? null) || !is_string($cursor['received_at'] ?? null)) { throw new \InvalidArgumentException('Cursor does not belong to this query.'); }
            $where[] = $request->byId() ? 's.id ' . $comparison . ' :cursor_id' : '(s.received_at ' . $comparison . ' :cursor_before OR (s.received_at = :cursor_equal AND s.id ' . $comparison . ' :cursor_id))';
            if (!$request->byId()) { $parameters[':cursor_before'] = $cursor['received_at']; $parameters[':cursor_equal'] = $cursor['received_at']; }
            $parameters[':cursor_id'] = $cursor['id'];
        }
        // Only non-sensitive headers; payload/selected columns are fetched through authorized detail services.
        $order = $request->byId() ? 's.id ' . $direction : 's.received_at ' . $direction . ', s.id ' . $direction;
        $sql = 'SELECT s.id, s.uuid, s.form_id, s.form_version_id, s.state, s.received_at, s.channel, s.action_status FROM ' . $this->db->table('submissions') . ' s WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order . ' LIMIT ' . ($request->limit + 1);
        return [$sql, $parameters, $fingerprint];
    }

    private static function repeatedScope(string $field, array $elements): array
    {
        $scope = []; $cursor = $elements[$field]['parent_uuid'] ?? null; $depth = 0;
        while ($cursor !== null) {
            if (++$depth > 64 || !isset($elements[$cursor])) { throw new \InvalidArgumentException('Invalid repeated filter ancestry.'); }
            $element = $elements[$cursor];
            if ($element['type'] === 'repeatable-group') { array_unshift($scope, $cursor); }
            $cursor = $element['parent_uuid'] ?? null;
        }
        return $scope;
    }

    private function filterValue(string $type, mixed $value): mixed
    {
        if (!is_string($value) && !is_int($value) && !is_bool($value)) { throw new \InvalidArgumentException('Invalid typed filter value.'); }
        if ($type === 'decimal') { return \Nicode\FormStudio\Validation\Decimal::normalize($value); }
        if ($type === 'integer') {
            if (filter_var($value, FILTER_VALIDATE_INT) === false) { throw new \InvalidArgumentException('Invalid integer filter.'); }
            return (int) $value;
        }
        if ($type === 'boolean') {
            return match ($value) { true, 1, '1' => 1, false, 0, '0' => 0, default => throw new \InvalidArgumentException('Invalid boolean filter.') };
        }
        if (in_array($type, ['date', 'datetime'], true)) {
            if (!is_string($value)) { throw new \InvalidArgumentException('Invalid date filter.'); }
            $format = $type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s';
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, new \DateTimeZone('UTC'));
            if (!$date || $date->format($format) !== $value) { throw new \InvalidArgumentException('Invalid date filter.'); }
        }
        return (string) $value;
    }
}
