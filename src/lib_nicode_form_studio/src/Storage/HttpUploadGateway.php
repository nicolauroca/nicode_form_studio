<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Storage;

use Nicode\FormStudio\Contract\StorageProviderInterface;

final readonly class HttpUploadGateway
{
    public function __construct(private UploadInspector $inspector, private StorageProviderInterface $storage, private ?\Closure $queueCleanup = null, private ?\Nicode\FormStudio\Infrastructure\Database\UploadJournal $journal = null) {}
    public function receive(array $files, UploadPolicy $policy, int $formId = 0): array
    {
        if (!array_is_list($files) || count($files) > $policy->maxFiles) { throw new \InvalidArgumentException('Upload count limit exceeded.'); }
        $stored = [];
        try {
            foreach ($files as $file) {
                if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_string($file['name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
                    throw new \InvalidArgumentException('Invalid HTTP upload provenance or upload error.');
                }
                $metadata = $this->inspector->inspect($file['tmp_name'], $file['name'], $policy);
                $stream = fopen($file['tmp_name'], 'rb');
                if ($stream === false) { throw new \RuntimeException('Uploaded file is unavailable.'); }
                try { $staging = $this->journal !== null ? $this->journal->stage($formId, $this->storage, $stream, $policy->maxBytes) : ['file' => $this->storage->put($stream, $policy->maxBytes)]; }
                finally { fclose($stream); }
                $stored[] = $staging + ['metadata' => $metadata, 'receipt' => ['uuid' => \Nicode\FormStudio\Domain\Uuid::create(), 'name' => $metadata['original_name'], 'mime' => $metadata['mime'], 'size' => $metadata['size']]];
            }
            return $stored;
        } catch (\Throwable $error) {
            $cleanupFailure = null;
            foreach ($stored as $entry) {
                try {
                    if ($this->journal !== null) { $this->journal->discardKey($entry['file']->provider, $entry['file']->key, $entry['staging_token'], $this->storage); }
                    else { UploadCleanup::discard($this->storage, $entry['file']->key, $this->queueCleanup); }
                }
                catch (\Throwable $failure) { $cleanupFailure ??= $failure; }
            }
            if ($cleanupFailure !== null) { throw new \RuntimeException('Upload cleanup and its durable outbox are unavailable.', previous: $cleanupFailure); }
            throw $error;
        }
    }
}
