<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Jobs;
use Nicode\FormStudio\Contract\TransactionalJobHandlerInterface;
use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Infrastructure\Database\UploadJournal;

final readonly class UploadCleanupHandler implements TransactionalJobHandlerInterface
{
    public function __construct(private UploadJournal $uploads) {}
    public function id(): string { return 'upload-cleanup'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['resumable' => true, 'internal_only' => true]; }
    public function validateConfiguration(array $configuration, string $path): array { return $configuration === [] ? [] : [new Diagnostic('job.upload_cleanup', $path, 'No upload cleanup parameters are accepted.')]; }
    public function run(JobLease $job, int $limit): JobProgress
    {
        if ($job->parameters !== []) { throw new \InvalidArgumentException('Invalid upload cleanup configuration.'); }
        $count = $this->uploads->reap($limit);
        return new JobProgress([], $count, complete: $count < $limit);
    }
}
