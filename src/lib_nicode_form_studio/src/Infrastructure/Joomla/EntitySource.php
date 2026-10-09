<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Infrastructure\Joomla;

use Nicode\FormStudio\Contract\DataSourceInterface;
use Nicode\FormStudio\Domain\{Diagnostic, Uuid};
use Nicode\FormStudio\Infrastructure\Database\Connection;

/** Approved read-only content queries. No configurable SQL, table or output columns. */
final readonly class EntitySource implements DataSourceInterface
{
    public function __construct(private Connection $db, private string $entity)
    {
        if (!in_array($entity, ['categories', 'articles'], true)) { throw new \InvalidArgumentException('Unsupported Joomla entity.'); }
    }
    public function id(): string { return 'joomla.' . $this->entity; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array
    {
        return ['id' => $this->id(), 'version' => $this->version(), 'cache' => false,
            'context_keys' => ['language', 'view_levels'], 'ttl' => 0, 'max_ttl' => 0, 'timeout' => 0, 'failure_modes' => ['closed'],
            'inputs' => 'declared category field UUID', 'dependency_parameters' => ['category_field'],
            'output' => ['value' => 'positive ID string', 'label' => 'title', 'enabled' => 'boolean'],
            'value_mapping' => 'id', 'label_mapping' => 'title',
            'config_schema' => ['category_ids' => 'nonempty list of approved parent category IDs', 'category_field' => 'optional UUID selecting one approved category', 'max_options' => 'integer 1..5000, default 500; overflow fails closed']];
    }
    public function validateConfiguration(array $configuration, string $path): array
    {
        $errors = []; $ids = $configuration['category_ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > 500 || count(array_filter($ids, static fn ($id): bool => is_int($id) && $id > 0)) !== count($ids)) {
            $errors[] = new Diagnostic('source.categories', $path . '/category_ids', 'Choose between 1 and 500 positive category IDs.');
        }
        if (isset($configuration['category_field']) && !Uuid::valid($configuration['category_field'])) { $errors[] = new Diagnostic('source.category_field', $path . '/category_field', 'Category input must be a field UUID.'); }
        $limit = $configuration['max_options'] ?? 500;
        if (!is_int($limit) || $limit < 1 || $limit > 5000) { $errors[] = new Diagnostic('source.limit', $path . '/max_options', 'Option limit must be between 1 and 5000.'); }
        foreach (array_diff(array_keys($configuration), ['category_ids', 'category_field', 'max_options']) as $key) { $errors[] = new Diagnostic('source.configuration', $path . '/' . $key, 'Unknown entity source setting.'); }
        return $errors;
    }
    public function options(array $configuration, array $inputs, array $trustedContext): array
    {
        if ($this->validateConfiguration($configuration, '/source') !== []) { throw new \DomainException('Invalid Joomla entity source configuration.'); }
        $levels = $trustedContext['view_levels'] ?? []; $language = $trustedContext['language'] ?? null;
        if (!is_array($levels) || $levels === [] || !is_string($language) || $language === '' || count(array_filter($levels, static fn ($id): bool => is_int($id) && $id > 0)) !== count($levels)) { return []; }
        $categories = array_values(array_unique($configuration['category_ids']));
        if (isset($configuration['category_field'])) {
            $selected = $inputs[$configuration['category_field']] ?? null;
            if (is_string($selected) && preg_match('/^[1-9][0-9]*$/D', $selected) === 1 && strlen($selected) < 11) { $selected = (int) $selected; }
            if (!is_int($selected) || !in_array($selected, $categories, true)) { return []; }
            $categories = [$selected];
        }
        $parameters = []; $q = $this->db->quote(...);
        $list = static function (array $values, string $prefix) use (&$parameters): string {
            $names = []; foreach (array_values($values) as $i => $value) { $name = ':' . $prefix . $i; $names[] = $name; $parameters[$name] = $value; } return implode(', ', $names);
        };
        $access = $list(array_values(array_unique($levels)), 'access');
        $ancestorAccess = $list(array_values(array_unique($levels)), 'ancestor');
        $scope = $list($categories, 'scope');
        $parameters[':language'] = $parameters[':ancestor_language'] = $language;
        $conditions = ['c.' . $q('extension') . " = 'com_content'", 'c.' . $q('published') . ' = 1', 'c.' . $q('access') . ' IN (' . $access . ')', "c." . $q('language') . " IN ('*', :language)"];
        $conditions[] = 'NOT EXISTS (SELECT 1 FROM ' . $q('#__categories') . ' ancestor WHERE ancestor.' . $q('lft') . ' < c.' . $q('lft') . ' AND ancestor.' . $q('rgt') . ' > c.' . $q('rgt') . ' AND (ancestor.' . $q('published') . ' <> 1 OR ancestor.' . $q('access') . ' NOT IN (' . $ancestorAccess . ') OR ancestor.' . $q('language') . " NOT IN ('*', :ancestor_language)))";
        $from = $q('#__categories') . ' c'; $alias = 'c';
        if ($this->entity === 'categories') { $conditions[] = 'c.' . $q('parent_id') . ' IN (' . $scope . ')'; }
        else {
            $alias = 'a'; $from = $q('#__content') . ' a INNER JOIN ' . $from . ' ON c.' . $q('id') . ' = a.' . $q('catid');
            $conditions[] = 'a.' . $q('catid') . ' IN (' . $scope . ')';
            $conditions[] = 'a.' . $q('state') . ' = 1';
            $conditions[] = 'a.' . $q('access') . ' IN (' . $list($levels, 'article_access') . ')';
            $conditions[] = 'a.' . $q('language') . " IN ('*', :article_language)"; $parameters[':article_language'] = $language;
            foreach (['publish_up' => '<=', 'publish_down' => '>'] as $column => $operator) {
                $parameters[':' . $column] = gmdate('Y-m-d H:i:s');
                $conditions[] = '(a.' . $q($column) . ' IS NULL OR a.' . $q($column) . ' ' . $operator . ' :' . $column . ')';
            }
        }
        $limit = $configuration['max_options'] ?? 500;
        $rows = $this->db->rows('SELECT ' . $alias . '.' . $q('id') . ', ' . $alias . '.' . $q('title') . ' FROM ' . $from . ' WHERE ' . implode(' AND ', $conditions) . ' ORDER BY ' . $alias . '.' . $q($this->entity === 'categories' ? 'lft' : 'ordering') . ', ' . $alias . '.' . $q('id') . ' LIMIT ' . ($limit + 1), $parameters);
        if (count($rows) > $limit) { throw new \DomainException('Joomla source exceeds its configured option limit.'); }
        return array_map(static fn (array $row): array => ['value' => (string) $row['id'], 'label' => (string) $row['title'], 'enabled' => true], $rows);
    }
}
