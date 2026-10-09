<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Compiler;

use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Domain\FormSpec;

final readonly class CompilationResult
{
    /** @param list<Diagnostic> $diagnostics */
    public function __construct(public ?FormSpec $spec, public array $diagnostics)
    {
    }

    public function successful(): bool
    {
        return $this->spec !== null;
    }
}
