<?php
declare(strict_types=1);

namespace Nicode\FormStudio\DataSource;

use Nicode\FormStudio\Contract\CacheInterface;
use Nicode\FormStudio\Domain\CanonicalJson;
use Nicode\FormStudio\Registry\DataSourceRegistry;

final readonly class OptionResolver
{
    public function __construct(private DataSourceRegistry $sources, private CacheInterface $cache, private ?\Nicode\FormStudio\Contract\LifecycleEventsInterface $events = null, private ?\Closure $failureLog = null) {}

    public function resolve(array $source, array $values, array $trustedContext = []): array
    {
        try { return $this->load($source, $values, $trustedContext); }
        catch (\Throwable $error) {
            try { if ($this->failureLog !== null) { ($this->failureLog)(); } } catch (\Throwable) {}
            throw $error;
        }
    }

    private function load(array $source, array $values, array $trustedContext): array
    {
        $provider = $this->sources->get($source['type']);
        $config = $source['config'] ?? [];
        $inputs = array_intersect_key($values, array_flip($source['dependencies'] ?? []));
        $metadata = $provider->metadata();
        $context = array_intersect_key($trustedContext, array_flip($metadata['context_keys'] ?? []));
        $ttl = $source['ttl'] ?? 0;
        if (!is_int($ttl) || $ttl < 0 || $ttl > 86400) { throw new \InvalidArgumentException('Invalid source TTL.'); }
        $key = 'nfs.source.' . hash('sha256', CanonicalJson::encode([$provider->id(), $provider->version(), $config, $inputs, $context, $ttl]));
        $cacheable = ($metadata['cache'] ?? false) && $ttl > 0;
        if ($cacheable && ($cached = $this->cache->get($key)) !== null) {
            try { $this->validate($cached); }
            catch (\DomainException) { $this->cache->delete($key); $cached = null; }
            if ($cached !== null) { $this->events?->emit('ResolveDataSource', ['provider_id' => $provider->id(), 'provider_version' => $provider->version(), 'cache_hit' => true]); return $cached; }
        }
        $this->events?->emit('ResolveDataSource', ['provider_id' => $provider->id(), 'provider_version' => $provider->version(), 'cache_hit' => false]);
        $result = $provider->options($config, $inputs, $context);
        $this->validate($result);
        if ($cacheable) { $this->cache->set($key, $result, $ttl); }
        return $result;
    }
    private function validate(mixed $result): void
    {
        if (!is_array($result) || !array_is_list($result)) { throw new \DomainException('Data source returned an invalid option collection.'); }
        $identities = [];
        foreach ($result as $option) {
            if (!is_array($option) || !is_string($option['value'] ?? null) || !is_string($option['label'] ?? null) || isset($identities[$option['value']])) {
                throw new \DomainException('Data source returned invalid or duplicate option identities.');
            }
            $identities[$option['value']] = true;
            if (!mb_check_encoding($option['value'], 'UTF-8') || !mb_check_encoding($option['label'], 'UTF-8')) { throw new \DomainException('Data source returned invalid text.'); }
            foreach (['enabled', 'default'] as $flag) {
                if (array_key_exists($flag, $option) && !is_bool($option[$flag])) { throw new \DomainException('Data source returned an invalid option flag.'); }
            }
        }
    }
}
