<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Security;

final readonly class RedirectPolicy
{
    public function __construct(private array $approvedHosts = []) {}
    public function validate(string $destination): string
    {
        if ($destination === '' || strlen($destination) > 2048) { throw new \InvalidArgumentException('Invalid redirect destination.'); }
        $decoded = $destination;
        for ($i = 0; $i < 3; $i++) { $decoded = rawurldecode($decoded); }
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $decoded) || str_starts_with($decoded, '//')) { throw new \InvalidArgumentException('Unsafe redirect destination.'); }
        if (str_starts_with($destination, '/') || str_starts_with($destination, 'index.php?')) { return $destination; }
        $parts = parse_url($destination);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || ($parts['port'] ?? 443) !== 443 || !in_array(strtolower($parts['host'] ?? ''), $this->approvedHosts, true)) { throw new \InvalidArgumentException('Redirect destination is not approved.'); }
        return $destination;
    }
}
