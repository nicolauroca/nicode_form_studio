<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Domain;

final readonly class Diagnostic implements \JsonSerializable
{
    public function __construct(public string $code, public string $path, public string $message, public string $severity = 'ERROR')
    {
        if (!in_array($severity, ['ERROR', 'WARNING', 'INFO'], true)) {
            throw new \InvalidArgumentException('Invalid diagnostic severity.');
        }
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
