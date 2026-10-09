<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Registry;

use Nicode\FormStudio\Contract\SearchProviderInterface;

/** @extends ProviderRegistry<SearchProviderInterface> */
final class SearchProviderRegistry extends ProviderRegistry
{
    public function __construct() { parent::__construct(SearchProviderInterface::class); }
}
