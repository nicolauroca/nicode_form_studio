<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Health;

use Joomla\Database\DatabaseInterface;
use Nicode\FormStudio\Infrastructure\Database\Connection;
use Nicode\FormStudio\Infrastructure\Database\SchemaDefinition;

final readonly class SchemaTypes
{
    public function __construct(private DatabaseInterface $database) {}
    public function inspect(): array
    {
        $db = new Connection($this->database); $postgres = $db->isPostgresql(); $failed = 0;
        foreach (SchemaDefinition::tables() as $name => $definition) {
            try {
                $rows = $db->rows('SELECT column_name, data_type, is_nullable, character_maximum_length, numeric_precision, numeric_scale, datetime_precision, collation_name' . ($postgres ? ', is_identity, identity_generation, is_generated' : ', column_type, character_set_name, extra') . ' FROM information_schema.columns WHERE table_schema = ' . ($postgres ? 'current_schema()' : 'DATABASE()') . ' AND table_name = :table', [':table' => $this->database->replacePrefix('#__nicode_form_studio_' . $name)]);
                // MySQL's data dictionary can preserve uppercase catalog labels.
                $columns = array_column(array_map(static fn (array $row): array => array_change_key_case($row, CASE_LOWER), $rows), null, 'column_name');
                foreach (['id' => 'bigint!', ...$definition['columns']] as $column => $type) {
                    if (!isset($columns[$column]) || !self::matches($columns[$column], SchemaDefinition::columnSqlType($column, $type, $postgres), str_ends_with($type, '?')) || !self::storageProperties($columns[$column], $column === 'id', $postgres)) { $failed++; break; }
                }
            } catch (\Throwable) { $failed++; }
        }
        return ['status' => $failed === 0 ? 'ok' : 'unavailable', 'count' => $failed, 'more' => false];
    }
    public static function matches(array $column, string $expected, bool $nullable): bool
    {
        if (($column['is_nullable'] ?? null) !== ($nullable ? 'YES' : 'NO') || preg_match('/unsigned|zerofill/i', $column['column_type'] ?? '')) { return false; }
        $type = strtoupper($column['data_type'] ?? '');
        $type = ['CHARACTER' => 'CHAR', 'CHARACTER VARYING' => 'VARCHAR', 'INT' => 'INTEGER', 'NUMERIC' => 'DECIMAL'][$type] ?? $type;
        if (in_array($type, ['CHAR', 'VARCHAR', 'VARBINARY'], true)) { $type .= '(' . (int) ($column['character_maximum_length'] ?? 0) . ')'; }
        elseif ($type === 'DECIMAL') { $type .= '(' . (int) ($column['numeric_precision'] ?? 0) . ',' . (int) ($column['numeric_scale'] ?? 0) . ')'; }
        elseif ($type === 'DATETIME') { $type .= '(' . (int) ($column['datetime_precision'] ?? 0) . ')'; }
        elseif ($type === 'TIMESTAMP WITHOUT TIME ZONE') { $type = 'TIMESTAMP(' . (int) ($column['datetime_precision'] ?? 0) . ') WITHOUT TIME ZONE'; }
        return $type === $expected;
    }
    public static function storageProperties(array $column, bool $identity, bool $postgres): bool
    {
        if ($postgres) {
            return ($column['is_identity'] ?? null) === ($identity ? 'YES' : 'NO')
                && (!$identity || ($column['identity_generation'] ?? null) === 'BY DEFAULT')
                && ($column['is_generated'] ?? null) === 'NEVER'
                && ($column['collation_name'] ?? null) === null;
        }
        $extra = strtolower($column['extra'] ?? '');
        if (str_contains($extra, 'auto_increment') !== $identity || str_contains($extra, 'virtual generated') || str_contains($extra, 'stored generated') || str_contains($extra, 'persistent')) { return false; }
        $text = in_array(strtolower($column['data_type'] ?? ''), ['char', 'varchar', 'longtext'], true);
        return ($column['character_set_name'] ?? null) === ($text ? 'utf8mb4' : null)
            && ($column['collation_name'] ?? null) === ($text ? 'utf8mb4_bin' : null);
    }
}
