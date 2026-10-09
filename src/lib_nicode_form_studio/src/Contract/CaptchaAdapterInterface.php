<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Contract;

use Nicode\FormStudio\Security\CaptchaPolicy;

interface CaptchaAdapterInterface
{
    /** @return list<string> */
    public function available(): array;
    public function assertAvailable(CaptchaPolicy $policy): void;
    public function render(CaptchaPolicy $policy, string $instance): string;
    public function validate(CaptchaPolicy $policy, ?string $answer): void;
}
