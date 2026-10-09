<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Domain\Uuid;
use Nicode\FormStudio\Infrastructure\Database\Connection;
use Nicode\FormStudio\Infrastructure\Database\FormRepository;
use Nicode\FormStudio\Registry\StorageProviderRegistry;
use Nicode\FormStudio\Storage\Download;

final readonly class FileDownloads
{
    public function __construct(private Connection $db, private FormRepository $forms, private StorageProviderRegistry $storage, private \Closure $authorize) {}
    public function open(int $form, string $uuid, int $actor): Download
    {
        if (!Uuid::valid($uuid) || !(($this->authorize)($actor, $form, 'formstudio.submissions.view'))) { throw new \OutOfBoundsException('File unavailable.'); }
        $row = $this->db->row('SELECT f.*, s.form_version_id, s.canonical_payload, s.uuid AS submission_uuid FROM ' . $this->db->table('submission_files') . ' f JOIN ' . $this->db->table('submissions') . ' s ON s.id = f.submission_id WHERE f.uuid = :uuid AND s.form_id = :form AND s.anonymized_at IS NULL', [':uuid' => $uuid, ':form' => $form]);
        if (!$row) { throw new \OutOfBoundsException('File unavailable.'); }
        $definition = $this->forms->version($form, (int) $row['form_version_id'])->toArray();
        $fields = array_column($definition['fields'], null, 'uuid'); $field = $fields[$row['field_uuid']] ?? null;
        if (!$field || !in_array($field['type'], ['file', 'multiple-files'], true) || (($field['sensitive'] ?? false) && !(($this->authorize)($actor, $form, 'formstudio.submissions.view_sensitive')))) { throw new \OutOfBoundsException('File unavailable.'); }
        try {
            $payload = json_decode($row['canonical_payload'], true, 512, JSON_THROW_ON_ERROR);
            $key = \Nicode\FormStudio\Submission\StoredFileAddress::key($row);
            if (array_key_exists('instances', $payload)) {
                $instances = new \Nicode\FormStudio\Domain\RepeatedInstances($definition['elements'], $payload['instances']);
                if (!$instances->contains(\Nicode\FormStudio\Domain\FieldAddress::fromKey($key))) { throw new \InvalidArgumentException('Unknown instance.'); }
            } elseif ($row['instance_path'] !== '') { throw new \InvalidArgumentException('Unexpected instance.'); }
            if (!\Nicode\FormStudio\Submission\StoredFileAddress::matches($row, $payload['values'])) { throw new \InvalidArgumentException('Missing canonical receipt.'); }
        } catch (\InvalidArgumentException|\JsonException $error) { throw new \OutOfBoundsException('File unavailable.', 0, $error); }
        if (!$this->storage->has($row['provider'])) { throw new \OutOfBoundsException('File unavailable.'); }
        $provider = $this->storage->get($row['provider']);
        if (!$provider->exists($row['storage_key'])) { throw new \OutOfBoundsException('File unavailable.'); }
        $stream = $provider->open($row['storage_key']);
        try {
            $this->db->insert('audit_log', ['correlation_id' => Uuid::create(), 'actor_id' => $actor, 'event_type' => 'submission.file_download', 'form_id' => $form, 'submission_uuid' => $row['submission_uuid'], 'created_at' => gmdate('Y-m-d H:i:s'), 'safe_metadata' => '{}']);
        } catch (\Throwable $error) { fclose($stream); throw $error; }
        return new Download($stream, ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) $row['size_bytes'], 'Content-Disposition' => 'attachment; filename="download"; filename*=UTF-8\'\'' . rawurlencode($row['original_name']), 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
