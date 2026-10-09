<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Registry;

use Nicode\FormStudio\Contract\RuleOperatorInterface;
use Nicode\FormStudio\Rules\CoreOperator;

/** @extends ProviderRegistry<RuleOperatorInterface> */
final class RuleOperatorRegistry extends ProviderRegistry
{
    public function __construct() { parent::__construct(RuleOperatorInterface::class); }
    public static function core(): self
    {
        $registry = new self();
        foreach (CoreOperator::IDS as $id) { $registry->register(new CoreOperator($id)); }
        return $registry;
    }
}
