<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Jobs;

use Nicode\FormStudio\Contract\JobHandlerInterface;
use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Infrastructure\Database\JobRepository;
use Nicode\FormStudio\Registry\StorageProviderRegistry;

/** Internal outbox job. Never enqueue object keys supplied by an HTTP request. */
final readonly class FileCleanupHandler implements JobHandlerInterface
{
    public function __construct(private StorageProviderRegistry $storage, private JobRepository $jobs) {}
    public function id(): string { return 'file-cleanup'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['resumable' => true, 'idempotent' => true, 'internal_only' => true]; }
    public function validateConfiguration(array $configuration, string $path): array
    {
        $objects = $configuration['objects'] ?? null;
        if (!is_array($objects) || !array_is_list($objects)) { return [new Diagnostic('job.objects', $path, 'Expected cleanup object list.')]; }
        foreach ($objects as $object) {
            if (!is_array($object) || !is_string($object['provider'] ?? null) || !$this->storage->has($object['provider']) || !is_string($object['storage_key'] ?? null) || $object['storage_key'] === '') { return [new Diagnostic('job.object', $path, 'Storage provider or owned key unavailable.')]; }
        }
        return [];
    }
    public function run(JobLease $job, int $limit): JobProgress
    {
        if ($this->validateConfiguration($job->parameters, '/job') !== [] || $limit < 1 || $limit > 500) { throw new \DomainException('Invalid cleanup configuration.'); }
        $offset = (int) ($job->cursor['offset'] ?? 0); $objects = $job->parameters['objects'];
        $chunk = array_slice($objects, $offset, $limit);
        foreach ($chunk as $object) {
            $this->jobs->renew($job); $provider = $this->storage->get($object['provider']);
            try { if ($provider->exists($object['storage_key'])) { $provider->delete($object['storage_key']); } }
            catch (\RuntimeException) { throw new RetryableJobFailure('file_cleanup_unavailable', 60); }
        }
        return new JobProgress(['offset' => $offset + count($chunk)], count($chunk), complete: $offset + count($chunk) >= count($objects));
    }
}
