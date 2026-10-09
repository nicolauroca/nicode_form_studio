<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Contract;

interface RuleOperatorInterface extends ProviderInterface
{
    public function evaluate(mixed $left, mixed $right, string $datatype): bool;
}
