<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Registry;

use Nicode\FormStudio\Contract\FieldTypeInterface;

/** @extends ProviderRegistry<FieldTypeInterface> */
final class FieldTypeRegistry extends ProviderRegistry
{
    public function __construct()
    {
        parent::__construct(FieldTypeInterface::class);
    }
}
