<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Infrastructure\Joomla;

use Joomla\CMS\Cache\Cache;
use Nicode\FormStudio\Contract\CacheInterface;
use Nicode\FormStudio\DataSource\RequestCache;
use Nicode\FormStudio\Domain\CanonicalJson;

/** Optional Joomla cache acceleration; failures always fall back to resolving the source. */
final class SourceCache implements CacheInterface
{
    private readonly RequestCache $local;
    private readonly \Closure $clock;
    public function __construct(private readonly Cache $cache, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? time(...);
        $this->local = new RequestCache($this->clock);
    }
    public function get(string $key): mixed
    {
        if (($local = $this->local->get($key)) !== null) { return $local; }
        try {
            $raw = $this->cache->get($key, 'com_nicode_form_studio.sources');
            if (!is_string($raw)) { return null; }
            $entry = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($entry) || !is_int($entry['expires'] ?? null) || $entry['expires'] <= ($this->clock)() || !array_key_exists('value', $entry)) {
                $this->delete($key); return null;
            }
            $this->local->set($key, $entry['value'], $entry['expires'] - ($this->clock)());
            return $entry['value'];
        } catch (\Throwable) { return null; }
    }
    public function diagnostics(): array
    {
        try {
            if (!$this->cache->getCaching()) { return ['status' => 'not_configured', 'reason' => 'cache_disabled']; }
            // Read an unpredictable reserved key directly, bypassing local fallback.
            // A miss is normal and cannot prove the backend's ability to write.
            $this->cache->get('health-' . bin2hex(random_bytes(16)), 'com_nicode_form_studio.sources');
            return ['status' => 'ok', 'reason' => 'cache_read_unverified'];
        } catch (\Throwable) { return ['status' => 'unavailable', 'reason' => 'cache_backend_unavailable']; }
    }
    public function set(string $key, mixed $value, int $ttl): void
    {
        if ($ttl < 0 || $ttl > 86400) { throw new \InvalidArgumentException('Invalid source cache TTL.'); }
        if ($ttl === 0) { $this->delete($key); return; }
        $encoded = CanonicalJson::encode(['expires' => ($this->clock)() + $ttl, 'value' => $value]);
        $this->local->set($key, $value, $ttl);
        try { $this->cache->store($encoded, $key, 'com_nicode_form_studio.sources'); } catch (\Throwable) { /* Cache is optional. */ }
    }
    public function delete(string $key): void
    {
        $this->local->delete($key);
        try { $this->cache->remove($key, 'com_nicode_form_studio.sources'); } catch (\Throwable) { /* Exact expiry also rejects old entries. */ }
    }
}
