<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Jobs;

use Nicode\FormStudio\Contract\JobHandlerInterface;

/** Preserve queued work when configured storage is temporarily unavailable. */
final readonly class UnavailableStorageHandler implements JobHandlerInterface
{
    public function __construct(private string $type) {}
    public function id(): string { return $this->type; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['resumable' => true, 'available' => false]; }
    public function validateConfiguration(array $configuration, string $path): array { return []; }
    public function run(JobLease $job, int $limit): JobProgress { throw new RetryableJobFailure('storage_unavailable', 300); }
}
