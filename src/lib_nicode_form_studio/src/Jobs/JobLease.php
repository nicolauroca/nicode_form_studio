<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Jobs;

final readonly class JobLease
{
    public function __construct(public int $id, public string $uuid, public string $type, public int $creator, public array $parameters, public array $cursor, public string $token, public int $revision, public int $processed, public int $failed) {}
}
