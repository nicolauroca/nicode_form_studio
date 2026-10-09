<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Contract;

interface LifecycleEventsInterface
{
    /** Before/resolve listeners may veto by throwing; after listeners are observational. */
    public function emit(string $phase, array $context): void;
}
