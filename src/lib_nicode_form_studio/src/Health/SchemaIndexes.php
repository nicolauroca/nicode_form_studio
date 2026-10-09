<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Health;

use Joomla\Database\DatabaseInterface;
use Nicode\FormStudio\Infrastructure\Database\Connection;
use Nicode\FormStudio\Infrastructure\Database\SchemaDefinition;

/** Catalog-only checks; equivalent index names are accepted. */
final readonly class SchemaIndexes
{
    public function __construct(private DatabaseInterface $database) {}
    public function inspect(): array
    {
        $failed = 0; $db = new Connection($this->database);
        foreach (SchemaDefinition::tables() as $name => $definition) {
            try {
                $table = $this->database->replacePrefix('#__nicode_form_studio_' . $name);
                $actual = [];
                if ($db->isPostgresql()) {
                    $rows = $db->rows('SELECT i.indexrelid AS identity, i.indisprimary AS primary_key, i.indisunique AS unique_key, pg_get_indexdef(i.indexrelid, k.ordinality::integer, true) AS column_name, k.ordinality AS sequence FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_am a ON a.oid = c.relam CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY k(attnum, ordinality) WHERE i.indrelid = to_regclass(:table) AND i.indisvalid AND i.indisready AND i.indpred IS NULL AND i.indexprs IS NULL AND 0 = ALL (i.indoption::smallint[]) AND a.amname = :method AND k.ordinality <= i.indnkeyatts ORDER BY i.indexrelid, k.ordinality', [':table' => $table, ':method' => 'btree']);
                    foreach ($rows as $row) {
                        $key = $row['identity'];
                        $actual[$key]['primary'] = in_array($row['primary_key'], [true, 1, '1', 't'], true);
                        $actual[$key]['unique'] = in_array($row['unique_key'], [true, 1, '1', 't'], true);
                        $actual[$key]['columns'][] = $row['column_name'];
                    }
                } else {
                    foreach ($this->database->getTableKeys($table) as $row) {
                        $key = $row->Key_name;
                        $actual[$key]['primary'] = $key === 'PRIMARY';
                        $actual[$key]['unique'] = (int) $row->Non_unique === 0;
                        $actual[$key]['columns'][(int) $row->Seq_in_index] = $row->Column_name;
                        $actual[$key]['invalid'] = ($actual[$key]['invalid'] ?? false) || $row->Sub_part !== null || strtoupper($row->Index_type) !== 'BTREE' || ($row->Collation ?? 'A') !== 'A' || ($row->Visible ?? 'YES') !== 'YES' || ($row->Ignored ?? 'NO') !== 'NO';
                    }
                    foreach ($actual as &$index) { ksort($index['columns']); $index['columns'] = array_values($index['columns']); } unset($index);
                }
                $expected = [['primary' => true, 'unique' => true, 'columns' => ['id']]];
                foreach ($definition['unique'] ?? [] as $columns) { $expected[] = ['primary' => false, 'unique' => true, 'columns' => $columns]; }
                foreach ($definition['indexes'] ?? [] as $columns) { $expected[] = ['primary' => false, 'unique' => false, 'columns' => $columns]; }
                foreach ($expected as $index) {
                    $found = false;
                    foreach ($actual as $candidate) {
                        if (!($candidate['invalid'] ?? false) && $candidate['primary'] === $index['primary'] && $candidate['unique'] === $index['unique'] && $candidate['columns'] === $index['columns']) { $found = true; break; }
                    }
                    if (!$found) { $failed++; break; }
                }
            } catch (\Throwable) { $failed++; }
        }
        return ['status' => $failed === 0 ? 'ok' : 'unavailable', 'count' => $failed, 'more' => false];
    }
}
