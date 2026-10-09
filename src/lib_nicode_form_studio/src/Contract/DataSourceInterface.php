<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Contract;

interface DataSourceInterface extends ProviderInterface
{
    /** @return list<array{value:string,label:string,enabled?:bool}> */
    public function options(array $configuration, array $inputs, array $trustedContext): array;
}
