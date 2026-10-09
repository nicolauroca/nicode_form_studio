<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Health;

use Nicode\FormStudio\Infrastructure\Database\Connection;
use Nicode\FormStudio\Infrastructure\Database\SchemaDefinition;

final readonly class SchemaColumns
{
    public function __construct(private Connection $db) {}
    public function inspect(): array
    {
        $failed = 0;
        foreach (SchemaDefinition::tables() as $name => $table) {
            $columns = ['id', ...array_keys($table['columns'])];
            try {
                // Resolve every expected column without reading stored row values.
                $this->db->rows('SELECT ' . implode(', ', array_map($this->db->quote(...), $columns)) . ' FROM ' . $this->db->table($name) . ' WHERE 1 = 0');
            } catch (\Throwable) { $failed++; }
        }
        return ['status' => $failed === 0 ? 'ok' : 'unavailable', 'count' => $failed, 'more' => false];
    }
}
