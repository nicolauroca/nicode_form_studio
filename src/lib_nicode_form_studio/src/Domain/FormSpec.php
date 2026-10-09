<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Domain;

/** Immutable value; only the compiler should construct executable snapshots. */
final readonly class FormSpec
{
    public string $json;
    public string $hash;

    public function __construct(private array $definition)
    {
        if (($definition['schema_version'] ?? null) !== '1.0') {
            throw new \InvalidArgumentException('Unsupported FormSpec schema.');
        }
        $this->json = CanonicalJson::encode($definition);
        $this->hash = hash('sha256', $this->json);
    }

    public function toArray(): array
    {
        return $this->definition;
    }
}
