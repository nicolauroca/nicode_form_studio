<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Compiler;

final class CompilationException extends \DomainException
{
    public function __construct(public readonly array $diagnostics) { parent::__construct('Form compilation failed.'); }
}
