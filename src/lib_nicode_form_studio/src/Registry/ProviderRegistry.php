<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Registry;

use Nicode\FormStudio\Contract\ProviderInterface;

/** @template T of ProviderInterface */
class ProviderRegistry
{
    /** @var array<string, T> */
    private array $providers = [];
    private bool $frozen = false;

    /** @param class-string<T> $contract */
    public function __construct(private readonly string $contract = ProviderInterface::class)
    {
    }

    /** @param T $provider */
    public function register(ProviderInterface $provider): void
    {
        if ($this->frozen) {
            throw new \LogicException('Registry is frozen.');
        }
        if (!$provider instanceof $this->contract || preg_match('/^[a-z][a-z0-9_.-]*$/D', $provider->id()) !== 1) {
            throw new \InvalidArgumentException('Invalid provider contract or identifier.');
        }
        if (isset($this->providers[$provider->id()])) {
            throw new \LogicException('Duplicate provider: ' . $provider->id());
        }
        if (preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $provider->version()) !== 1) { throw new \InvalidArgumentException('Provider requires a semantic version.'); }
        \Nicode\FormStudio\Domain\CanonicalJson::encode($provider->metadata());
        $this->providers[$provider->id()] = $provider;
    }

    public function has(string $id): bool
    {
        return isset($this->providers[$id]);
    }

    /** @return T */
    public function get(string $id): ProviderInterface
    {
        return $this->providers[$id] ?? throw new \DomainException('Required provider unavailable: ' . $id);
    }

    public function metadata(): array
    {
        return array_map(static fn (ProviderInterface $provider): array => $provider->metadata(), $this->providers);
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }
}
