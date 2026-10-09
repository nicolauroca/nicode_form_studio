<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Contract;
use Nicode\FormStudio\Security\RateLimitResult;
interface RateLimiterInterface
{
    public function consume(string $scopeHash, int $limit, int $windowSeconds): RateLimitResult;
}
