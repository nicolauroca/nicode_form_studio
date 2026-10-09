<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Contract;

use Nicode\FormStudio\Domain\Diagnostic;

interface ProviderInterface
{
    public function id(): string;
    public function version(): string;
    public function metadata(): array;
    /** @return list<Diagnostic> */
    public function validateConfiguration(array $configuration, string $path): array;
}
