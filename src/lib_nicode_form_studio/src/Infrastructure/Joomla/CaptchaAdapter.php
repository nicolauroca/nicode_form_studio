<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Infrastructure\Joomla;

use Joomla\CMS\Captcha\CaptchaRegistry;
use Nicode\FormStudio\Contract\CaptchaAdapterInterface;
use Nicode\FormStudio\Security\CaptchaException;
use Nicode\FormStudio\Security\CaptchaPolicy;

/** Registry must be initialized by Joomla's DI service provider. */
final readonly class CaptchaAdapter implements CaptchaAdapterInterface
{
    public function __construct(
        private CaptchaRegistry $registry,
        private CaptchaPolicy $defaultPolicy,
        private ?string $joomlaDefault,
        private bool $allowNone = true,
    ) {
        if ($defaultPolicy->mode === 'inherit') { throw new \InvalidArgumentException('Global CAPTCHA policy cannot inherit itself.'); }
    }

    public function available(): array
    {
        $names = array_map(static fn ($provider): string => $provider->getName(), $this->registry->getAll());
        sort($names, SORT_STRING);
        return $names;
    }

    public function assertAvailable(CaptchaPolicy $policy): void { $this->resolve($policy); }

    public function render(CaptchaPolicy $policy, string $instance): string
    {
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $instance) !== 1) { throw new \InvalidArgumentException('Invalid instance identifier.'); }
        $name = $this->resolve($policy);
        if ($name === null) { return ''; }
        try {
            $html = $this->registry->get($name)->display('formstudio_captcha', ['id' => $instance . '-captcha', 'class' => 'nfs-captcha', 'data-formstudio-instance' => $instance]);
        } catch (\Throwable) {
            throw new CaptchaException('captcha_unavailable');
        }
        if (trim($html) === '') { throw new CaptchaException('captcha_unavailable'); }
        return $html;
    }

    public function validate(CaptchaPolicy $policy, ?string $answer): void
    {
        $name = $this->resolve($policy);
        if ($name === null) { return; }
        try { $accepted = $this->registry->get($name)->checkAnswer($answer); }
        catch (\Throwable) { throw new CaptchaException('captcha_unavailable'); }
        if (!$accepted) { throw new CaptchaException('captcha_error'); }
    }

    private function resolve(CaptchaPolicy $policy): ?string
    {
        if ($policy->mode === 'inherit') { $policy = $this->defaultPolicy; }
        $name = match ($policy->mode) { 'joomla' => $this->joomlaDefault, 'provider' => $policy->provider, 'none' => null };
        if ($name === null || $name === '' || $name === '0' || $name === '-1') {
            if (!$this->allowNone || $policy->mode === 'provider') { throw new CaptchaException('captcha_required'); }
            return null;
        }
        if (!$this->registry->has($name)) { throw new CaptchaException('captcha_unavailable'); }
        return $name;
    }
}
