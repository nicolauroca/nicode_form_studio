<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Infrastructure\Database;

/** Same-version pre-release upgrade. Empty scope preserves ordinary answers. */
final class InstanceSchema
{
    public static function upgrade(\Joomla\Database\DatabaseInterface $database): void
    {
        $db = new Connection($database); $postgres = $db->isPostgresql();
        foreach (['submission_index', 'submission_files'] as $table) {
            $name = $database->replacePrefix('#__nicode_form_studio_' . $table);
            $columns = $database->getTableColumns($name, false);
            foreach (['instance_path' => "VARCHAR(4735) NOT NULL DEFAULT ''", 'instance_hash' => "CHAR(64) NOT NULL DEFAULT '" . hash('sha256', '') . "'"] as $column => $type) {
                if (!isset($columns[$column])) { $db->execute('ALTER TABLE ' . $db->table($table) . ' ADD COLUMN ' . $db->quote($column) . ' ' . $type); }
            }
        }
        $name = $database->replacePrefix('#__nicode_form_studio_submission_index');
        $keys = [];
        if ($postgres) {
            foreach ($db->rows('SELECT c.conname, a.attname, k.ordinality FROM pg_constraint c CROSS JOIN LATERAL unnest(c.conkey) WITH ORDINALITY k(attnum, ordinality) JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum WHERE c.conrelid = to_regclass(:table) AND c.contype = :type ORDER BY c.conname, k.ordinality', [':table' => $name, ':type' => 'u']) as $row) { $keys[$row['conname']][] = $row['attname']; }
        } else {
            foreach ($database->getTableKeys($name) as $key) {
                if ((int) $key->Non_unique === 0) { $keys[$key->Key_name][(int) $key->Seq_in_index] = $key->Column_name; }
            }
            foreach ($keys as &$columns) { ksort($columns); $columns = array_values($columns); } unset($columns);
        }
        foreach ($keys as $key => $columns) {
            if ($columns !== ['submission_id', 'field_uuid', 'ordinal']) { continue; }
            // One ALTER keeps the old uniqueness until the replacement is ready.
            $db->execute('ALTER TABLE ' . $db->table('submission_index') . ' DROP ' . ($postgres ? 'CONSTRAINT ' : 'INDEX ') . $db->quote($key)
                . ', ADD CONSTRAINT ' . $db->quote($key) . ' UNIQUE (' . implode(', ', array_map($db->quote(...), ['submission_id', 'field_uuid', 'instance_hash', 'ordinal'])) . ')');
        }
    }
}
