<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Registry;

use Nicode\FormStudio\Contract\ValidatorInterface;
use Nicode\FormStudio\Validation\RelationalValidator;

/** @extends ProviderRegistry<ValidatorInterface> */
final class ValidatorRegistry extends ProviderRegistry
{
    public function __construct() { parent::__construct(ValidatorInterface::class); }
    public static function core(): self
    {
        $registry = new self();
        foreach (RelationalValidator::IDS as $id) { $registry->register(new RelationalValidator($id)); }
        return $registry;
    }
}
