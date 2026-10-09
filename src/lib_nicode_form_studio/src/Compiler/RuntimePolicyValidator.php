<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Compiler;
use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Privacy\RetentionPolicy;
use Nicode\FormStudio\Security\CaptchaPolicy;
final class RuntimePolicyValidator
{
    public function validate(array $draft): array
    {
        $errors = [];
        foreach (['security', 'privacy', 'persistence'] as $name) { if (isset($draft[$name]) && !is_array($draft[$name])) { $errors[] = new Diagnostic('policy.shape', '/' . $name, 'Expected a policy object.'); } }
        if ($errors !== []) { return $errors; }
        $security = $draft['security'] ?? []; $privacy = $draft['privacy'] ?? [];
        foreach (['minimum_seconds' => [0, 3600], 'attempt_lifetime' => [1, 86400], 'rate_limit' => [1, 1000000], 'rate_window' => [1, 86400]] as $key => [$min, $max]) {
            if (isset($security[$key]) && (!is_int($security[$key]) || $security[$key] < $min || $security[$key] > $max)) { $errors[] = new Diagnostic('policy.limit', '/security/' . $key, 'Policy limit must be an integer in the supported range.'); }
        }
        if (($security['minimum_seconds'] ?? 0) >= ($security['attempt_lifetime'] ?? 7200)) { $errors[] = new Diagnostic('policy.attempt_window', '/security', 'Minimum time must be shorter than attempt lifetime.'); }
        if (isset($security['honeypot']) && !is_bool($security['honeypot'])) { $errors[] = new Diagnostic('policy.boolean', '/security/honeypot', 'Expected a boolean.'); }
        try {
            $captcha = $security['captcha'] ?? [];
            if (!is_array($captcha) || !is_string($captcha['mode'] ?? 'inherit') || (isset($captcha['provider']) && !is_string($captcha['provider']))) { throw new \InvalidArgumentException(); }
            new CaptchaPolicy($captcha['mode'] ?? 'inherit', $captcha['provider'] ?? null);
        } catch (\InvalidArgumentException) { $errors[] = new Diagnostic('policy.captcha', '/security/captcha', 'Invalid CAPTCHA policy.'); }
        foreach (['store_user', 'store_ip', 'store_user_agent'] as $key) { if (array_key_exists($key, $privacy) && !is_bool($privacy[$key])) { $errors[] = new Diagnostic('policy.boolean', '/privacy/' . $key, 'Expected a boolean.'); } }
        try {
            $retention = $privacy['retention'] ?? [];
            if (!is_array($retention) || !is_string($retention['action'] ?? 'indefinite') || !is_int($retention['amount'] ?? 0) || !is_string($retention['unit'] ?? 'days')) { throw new \InvalidArgumentException(); }
            (new RetentionPolicy($retention['action'] ?? 'indefinite', $retention['amount'] ?? 0, $retention['unit'] ?? 'days'))->expiresAt(time());
        } catch (\InvalidArgumentException) { $errors[] = new Diagnostic('policy.retention', '/privacy/retention', 'Invalid retention policy.'); }
        if (!in_array($draft['persistence']['mode'] ?? 'full', ['full', 'metadata', 'none'], true)) { $errors[] = new Diagnostic('policy.persistence', '/persistence/mode', 'Unknown persistence mode.'); }
        return $errors;
    }
}
