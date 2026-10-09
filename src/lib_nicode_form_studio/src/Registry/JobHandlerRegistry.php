<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Registry;

use Nicode\FormStudio\Contract\JobHandlerInterface;

/** @extends ProviderRegistry<JobHandlerInterface> */
final class JobHandlerRegistry extends ProviderRegistry
{
    public function __construct() { parent::__construct(JobHandlerInterface::class); }
}
