<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Registry;

use Nicode\FormStudio\Contract\StorageProviderInterface;

/** @extends ProviderRegistry<StorageProviderInterface> */
final class StorageProviderRegistry extends ProviderRegistry
{
    public function __construct() { parent::__construct(StorageProviderInterface::class); }
}
