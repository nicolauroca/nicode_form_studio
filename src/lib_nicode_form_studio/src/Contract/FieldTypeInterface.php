<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Contract;

interface FieldTypeInterface extends ProviderInterface
{
    /** Throws InvalidArgumentException for invalid raw types or values. */
    public function normalize(mixed $value, array $configuration): mixed;
    /** @return list<string> Validation error codes, never raw values. */
    public function validate(mixed $value, array $configuration): array;
    public function serialize(mixed $value): mixed;
    public function indexType(): ?string;
    public function multiple(): bool;
}
