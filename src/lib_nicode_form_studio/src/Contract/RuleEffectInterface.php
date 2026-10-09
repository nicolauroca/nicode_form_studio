<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Contract;

interface RuleEffectInterface extends ProviderInterface
{
    /** Applies only to the target state, never to arbitrary application context. */
    public function apply(array $state, array $configuration): array;
}
