<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Jobs;

final readonly class JobProgress
{
    public function __construct(public array $cursor, public int $processed, public int $failed = 0, public bool $complete = false, public ?string $artifactKey = null)
    {
        if ($processed < 0 || $failed < 0) { throw new \InvalidArgumentException('Job counts must not be negative.'); }
    }
}
