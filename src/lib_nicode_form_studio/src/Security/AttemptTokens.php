<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Security;

/** Session-bound, expiring tokens carry no visitor data. Persistence owns replay. */
final readonly class AttemptTokens
{
    public function __construct(private string $key, private ?\Closure $clock = null)
    {
        if (strlen($key) < 32) { throw new \InvalidArgumentException('Attempt signing key must contain at least 32 bytes.'); }
    }
    public function issue(int $form, int $version, string $sessionBinding): string
    {
        if ($form < 1 || $version < 1 || $sessionBinding === '') { throw new \InvalidArgumentException('Invalid attempt context.'); }
        $payload = implode('.', [$form, $version, $this->now(), bin2hex(random_bytes(24))]);
        return $payload . '.' . hash_hmac('sha256', $payload . ':' . $sessionBinding, $this->key);
    }
    public function verify(string $token, int $form, int $version, string $sessionBinding, int $minimumSeconds = 0, int $maximumSeconds = 7200): string
    {
        if ($minimumSeconds < 0 || $maximumSeconds < 1 || $minimumSeconds >= $maximumSeconds) { throw new \InvalidArgumentException('Invalid attempt lifetime.'); }
        if (strlen($token) > 200 || preg_match('/^([1-9][0-9]*)\.([1-9][0-9]*)\.([1-9][0-9]*)\.([a-f0-9]{48})\.([a-f0-9]{64})$/D', $token, $parts) !== 1 || $sessionBinding === '') { throw new \DomainException('Invalid submission attempt.'); }
        $payload = implode('.', array_slice($parts, 1, 4)); $age = $this->now() - (int) $parts[3];
        if ((int) $parts[1] !== $form || (int) $parts[2] !== $version || !hash_equals(hash_hmac('sha256', $payload . ':' . $sessionBinding, $this->key), $parts[5]) || $age < $minimumSeconds || $age >= $maximumSeconds) { throw new \DomainException('Invalid submission attempt.'); }
        return hash('sha256', $token);
    }
    private function now(): int { return $this->clock === null ? time() : ($this->clock)(); }
}
