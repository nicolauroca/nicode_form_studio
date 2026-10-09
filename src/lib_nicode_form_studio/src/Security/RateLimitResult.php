<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Security;
final readonly class RateLimitResult
{
    public function __construct(public bool $allowed, public int $retryAfter) {}
}
