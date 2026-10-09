<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Jobs;
final class RetryableJobFailure extends \RuntimeException
{
    public function __construct(public readonly string $resultCode, public readonly int $delaySeconds = 30)
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $resultCode) !== 1 || $delaySeconds < 1 || $delaySeconds > 86400) { throw new \InvalidArgumentException('Invalid retry policy.'); }
        parent::__construct('Temporary job resource unavailable.');
    }
}
