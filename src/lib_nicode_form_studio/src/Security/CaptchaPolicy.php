<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Security;

final readonly class CaptchaPolicy
{
    public function __construct(public string $mode = 'inherit', public ?string $provider = null)
    {
        if (!in_array($mode, ['inherit', 'joomla', 'provider', 'none'], true)) { throw new \InvalidArgumentException('Unknown CAPTCHA policy.'); }
        if ($mode === 'provider' && ($provider === null || preg_match('/^[a-zA-Z0-9_.-]+$/D', $provider) !== 1)) {
            throw new \InvalidArgumentException('A CAPTCHA provider identifier is required.');
        }
    }
}
