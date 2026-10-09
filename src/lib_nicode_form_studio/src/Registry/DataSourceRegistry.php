<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Registry;

use Nicode\FormStudio\Contract\DataSourceInterface;

/** @extends ProviderRegistry<DataSourceInterface> */
final class DataSourceRegistry extends ProviderRegistry
{
    public function __construct() { parent::__construct(DataSourceInterface::class); }
}
