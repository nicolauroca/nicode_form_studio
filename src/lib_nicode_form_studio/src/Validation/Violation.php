<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Validation;

final readonly class Violation
{
    /** @param list<string> $fields */
    public function __construct(public string $code, public array $fields) {}
}
