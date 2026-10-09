<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Contract;

use Nicode\FormStudio\Jobs\JobLease;
use Nicode\FormStudio\Jobs\JobProgress;

interface JobHandlerInterface extends ProviderInterface
{
    /** A bounded, idempotent chunk. Cursor persistence follows successful execution. */
    public function run(JobLease $job, int $limit): JobProgress;
}
