<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Domain\CanonicalJson;
use Nicode\FormStudio\Domain\Uuid;
use Nicode\FormStudio\Infrastructure\Database\Connection;
use Nicode\FormStudio\Infrastructure\Database\FormRepository;
use Nicode\FormStudio\Infrastructure\Database\SubmissionRepository;

final readonly class SubmissionReader
{
    public function __construct(private SubmissionRepository $submissions, private FormRepository $forms, private Connection $db, private \Closure $authorize) {}
    public function read(int $form, int $submission, int $actor, string $purpose = 'view', bool $revealSensitive = false): array
    {
        return $this->readBatch($form, [$submission], $actor, $purpose, $revealSensitive)[$submission] ?? throw new \OutOfBoundsException('Submission not found.');
    }
    public function readBatch(int $form, array $ids, int $actor, string $purpose = 'view', bool $revealSensitive = false): array
    {
        if (!in_array($purpose, ['view', 'export'], true)) { throw new \InvalidArgumentException('Invalid read purpose.'); }
        if (!(($this->authorize)($actor, $form, 'formstudio.submissions.' . $purpose))) { throw new \DomainException('Submission access denied.'); }
        if ($revealSensitive && !(($this->authorize)($actor, $form, 'formstudio.submissions.view_sensitive'))) { throw new \DomainException('Sensitive access denied.'); }
        $ids = array_values(array_unique($ids));
        if ($ids === []) { return []; }
        if (count($ids) > 500) { throw new \InvalidArgumentException('Submission batch too large.'); }
        $parameters = [':form' => $form]; $keys = [];
        foreach ($ids as $i => $id) { if (!is_int($id) || $id < 1) { throw new \InvalidArgumentException('Invalid submission ID.'); } $keys[] = ':s' . $i; $parameters[':s' . $i] = $id; }
        $rows = $this->db->rows('SELECT id, uuid, form_version_id, received_at, state, action_status, anonymized_at, channel, locale, user_id, canonical_payload FROM ' . $this->db->table('submissions') . ' WHERE form_id = :form AND id IN (' . implode(', ', $keys) . ') ORDER BY id', $parameters);
        $versions = $this->forms->versions($form, array_map('intval', array_column($rows, 'form_version_id'))); $result = [];
        foreach ($rows as $row) { $result[(int) $row['id']] = $this->visible($form, $row, $versions[(int) $row['form_version_id']]->toArray(), $actor, $purpose, $revealSensitive); }
        return $result;
    }
    private function visible(int $form, array $row, array $definition, int $actor, string $purpose, bool $revealSensitive): array
    {
        $definition = \Nicode\FormStudio\Translation\DefinitionTranslations::resolve($definition, $row['locale']);
        $payload = json_decode($row['canonical_payload'], true, 512, JSON_THROW_ON_ERROR); $values = []; $labels = []; $masked = []; $sensitiveFields = 0;
        $fields = $definition['fields'];
        if (array_key_exists('instances', $payload)) {
            $instances = new \Nicode\FormStudio\Domain\RepeatedInstances($definition['elements'], $payload['instances']);
            $instances->bind($payload['values']); $byUuid = array_column($fields, null, 'uuid'); $fields = [];
            foreach ($instances->addresses() as $address) {
                $field = $byUuid[$address->field] ?? throw new \DomainException('Historical field definition unavailable.');
                $field['uuid'] = $address->key(); $fields[] = $field;
            }
        }
        foreach ($fields as $field) {
            $uuid = $field['uuid'];
            if (!array_key_exists($uuid, $payload['values']) || $field['type'] === 'password' || ($purpose === 'export' && !($field['include_export'] ?? !($field['sensitive'] ?? false)))) { continue; }
            if (($field['sensitive'] ?? false) && !$revealSensitive) { $masked[] = $uuid; continue; }
            $values[$uuid] = $payload['values'][$uuid]; $labels[$uuid] = $field['config']['label'] ?? $field['name'];
            if ($field['sensitive'] ?? false) { $sensitiveFields++; }
        }
        $metadata = $payload['request_metadata'] ?? [];
        $metadataVisible = $purpose === 'view' && $revealSensitive ? $metadata : [];
        if (($sensitiveFields > 0 || $metadataVisible !== []) && $purpose === 'view') {
            $this->db->insert('audit_log', ['correlation_id' => Uuid::create(), 'actor_id' => $actor, 'event_type' => 'submission.reveal_sensitive', 'form_id' => $form, 'submission_uuid' => $row['uuid'], 'created_at' => gmdate('Y-m-d H:i:s'), 'safe_metadata' => CanonicalJson::encode(['fields' => $sensitiveFields, 'request_metadata_items' => count($metadataVisible)])]);
        }
        return ['uuid' => $row['uuid'], 'form_id' => $form, 'form_version_id' => (int) $row['form_version_id'], 'received_at' => $row['received_at'], 'state' => $row['state'], 'action_status' => $row['action_status'], 'anonymized_at' => $row['anonymized_at'], 'values' => $values, 'labels' => $labels, 'masked' => $masked,
            'channel' => $row['channel'], 'locale' => $row['locale'], 'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
            'request_metadata' => $metadataVisible, 'request_metadata_masked' => $purpose === 'view' && !$revealSensitive && $metadata !== [],
            'layout' => $purpose === 'view' ? SubmissionPresentation::layout($definition, $values, $masked, $payload['instances'] ?? null) : [],
            'option_labels' => array_intersect_key($payload['option_labels'] ?? [], $values), 'consents' => array_intersect_key($payload['consents'] ?? [], $values)];
    }
}
