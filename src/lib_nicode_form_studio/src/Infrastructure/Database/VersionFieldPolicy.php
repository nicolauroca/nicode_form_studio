<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Infrastructure\Database;

use Nicode\FormStudio\Domain\FormSpec;

/** Rebuildable access metadata for indexed historical answers; snapshots remain authoritative. */
final readonly class VersionFieldPolicy
{
    public function __construct(private Connection $db) {}
    public function rebuild(int $form, int $version, ?FormSpec $indexPolicy = null): void
    {
        $this->db->transaction(function () use ($form, $version, $indexPolicy): void {
            $row = $this->db->row('SELECT spec, hash FROM ' . $this->db->table('form_versions') . ' WHERE form_id = :form AND id = :version FOR UPDATE', [':form' => $form, ':version' => $version]);
            if (!$row) { throw new \OutOfBoundsException('Form version unavailable.'); }
            $spec = new FormSpec(json_decode($row['spec'], true, 512, JSON_THROW_ON_ERROR));
            if (!hash_equals($row['hash'], $spec->hash)) { throw new \DomainException('Snapshot integrity check failed.'); }
            if ($indexPolicy !== null) { $spec = \Nicode\FormStudio\Search\HistoricalIndexPolicy::apply($spec, $indexPolicy); }
            $records = [];
            foreach ($spec->toArray()['fields'] as $field) {
                $records[] = ['form_id' => $form, 'form_version_id' => $version, 'field_uuid' => $field['uuid'], 'sensitive' => (int) ($field['sensitive'] ?? false), 'indexed' => (int) (($field['index'] ?? false) && ($field['persist'] ?? true) && $field['type'] !== 'password')];
            }
            $this->db->execute('DELETE FROM ' . $this->db->table('version_field_policy') . ' WHERE form_id = :form AND form_version_id = :version', [':form' => $form, ':version' => $version]);
            $this->db->insertMany('version_field_policy', $records);
        });
    }
}
