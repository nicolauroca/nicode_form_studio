<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Contract;

use Nicode\FormStudio\Actions\ActionContext;
use Nicode\FormStudio\Actions\ActionOutcome;

interface ActionInterface extends ProviderInterface
{
    public function execute(array $configuration, ActionContext $context): ActionOutcome;
}
