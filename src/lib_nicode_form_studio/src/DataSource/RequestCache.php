<?php
declare(strict_types=1);

namespace Nicode\FormStudio\DataSource;

use Nicode\FormStudio\Contract\CacheInterface;

/** Request-scoped cache. Cross-request adapters may implement the same contract. */
final class RequestCache implements CacheInterface
{
    private array $entries = [];
    private readonly \Closure $clock;
    public function __construct(?\Closure $clock = null) { $this->clock = $clock ?? time(...); }
    public function get(string $key): mixed
    {
        if (!isset($this->entries[$key]) || $this->entries[$key]['expires'] <= ($this->clock)()) { unset($this->entries[$key]); return null; }
        return $this->entries[$key]['value'];
    }
    public function set(string $key, mixed $value, int $ttl): void
    {
        if ($ttl < 0) { throw new \InvalidArgumentException('TTL must not be negative.'); }
        $this->entries[$key] = ['value' => $value, 'expires' => ($this->clock)() + $ttl];
    }
    public function delete(string $key): void { unset($this->entries[$key]); }
}
