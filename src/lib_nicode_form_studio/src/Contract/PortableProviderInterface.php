<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Contract;

/** Optional provider-owned export contract. Returned data must contain no credentials. */
interface PortableProviderInterface extends ProviderInterface
{
    public function exportConfiguration(array $configuration, string $mode): array;
}
