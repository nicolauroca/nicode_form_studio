<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Http;

final readonly class DestinationPolicy
{
    /** Exact administrator-controlled DNS names. No wildcards or visitor URLs. */
    public function __construct(private array $allowedHosts, private ?\Closure $resolver = null) {}
    public function host(string $url): string
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { throw new \DomainException('HTTP destination rejected.'); }
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['fragment']) || ($parts['port'] ?? 443) !== 443) { throw new \DomainException('HTTP destination rejected.'); }
        $host = strtolower($parts['host'] ?? '');
        if (strlen($host) > 253 || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host) !== 1 || !in_array($host, $this->allowedHosts, true)) { throw new \DomainException('HTTP destination rejected.'); }
        return $host;
    }
    public function resolve(string $url): array
    {
        $host = $this->host($url);
        if ($this->resolver !== null) { $addresses = ($this->resolver)($host); }
        else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA); $addresses = [];
            foreach ($records ?: [] as $record) { if (isset($record['ip']) || isset($record['ipv6'])) { $addresses[] = $record['ip'] ?? $record['ipv6']; } }
        }
        if (!is_array($addresses) || $addresses === []) { throw new \DomainException('HTTP destination unavailable.'); }
        foreach ($addresses as $address) { if (!is_string($address) || !PublicAddress::allowed($address)) { throw new \DomainException('HTTP destination rejected.'); } }
        return ['host' => $host, 'addresses' => array_values(array_unique($addresses))];
    }
}
