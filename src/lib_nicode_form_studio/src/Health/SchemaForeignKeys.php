<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Health;

use Joomla\Database\DatabaseInterface;
use Nicode\FormStudio\Infrastructure\Database\Connection;
use Nicode\FormStudio\Infrastructure\Database\SchemaDefinition;

final readonly class SchemaForeignKeys
{
    public function __construct(private DatabaseInterface $database) {}
    public function inspect(): array
    {
        $db = new Connection($this->database); $failed = 0;
        foreach (SchemaDefinition::tables() as $name => $definition) {
            $expected = [];
            if ($definition['form_fk'] ?? false) { $expected['form_id'] = 'forms'; }
            if ($definition['submission_fk'] ?? false) { $expected['submission_id'] = 'submissions'; }
            if ($expected === []) { continue; }
            try {
                $table = $this->database->replacePrefix('#__nicode_form_studio_' . $name);
                if ($db->isPostgresql()) {
                    $rows = $db->rows("SELECT s.attname AS source_column, t.relname AS target_table, d.attname AS target_column FROM pg_constraint c JOIN pg_class t ON t.oid = c.confrelid JOIN pg_namespace n ON n.oid = t.relnamespace JOIN pg_attribute s ON s.attrelid = c.conrelid AND s.attnum = c.conkey[1] JOIN pg_attribute d ON d.attrelid = c.confrelid AND d.attnum = c.confkey[1] WHERE c.conrelid = to_regclass(:table) AND c.contype = 'f' AND array_length(c.conkey, 1) = 1 AND array_length(c.confkey, 1) = 1 AND c.convalidated AND NOT c.condeferrable AND c.confupdtype IN ('a', 'r') AND c.confdeltype IN ('a', 'r') AND n.nspname = current_schema()", [':table' => $table]);
                } else {
                    $rows = $db->rows("SELECT MIN(k.COLUMN_NAME) AS source_column, MIN(k.REFERENCED_TABLE_NAME) AS target_table, MIN(k.REFERENCED_COLUMN_NAME) AS target_column FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = :table AND k.REFERENCED_TABLE_SCHEMA = DATABASE() AND r.UPDATE_RULE IN ('NO ACTION', 'RESTRICT') AND r.DELETE_RULE IN ('NO ACTION', 'RESTRICT') GROUP BY k.CONSTRAINT_NAME HAVING COUNT(*) = 1", [':table' => $table]);
                }
                $rows = array_map(static fn (array $row): array => array_change_key_case($row, CASE_LOWER), $rows);
                foreach ($expected as $column => $target) {
                    $match = ['source_column' => $column, 'target_table' => $this->database->replacePrefix('#__nicode_form_studio_' . $target), 'target_column' => 'id'];
                    if (!in_array($match, $rows, true)) { $failed++; break; }
                }
            } catch (\Throwable) { $failed++; }
        }
        return ['status' => $failed === 0 ? 'ok' : 'unavailable', 'count' => $failed, 'more' => false];
    }
}
