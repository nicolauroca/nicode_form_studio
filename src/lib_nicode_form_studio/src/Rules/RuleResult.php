<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Rules;

final readonly class RuleResult
{
    public function __construct(public array $states, public array $values, public int $iterations, public array $initialDefaults = [], public array $normalizationErrors = []) {}
}
