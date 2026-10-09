<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Contract;

use Nicode\FormStudio\Validation\Violation;

interface ValidatorInterface extends ProviderInterface
{
    /** Only active, normalized values are supplied. @return list<Violation> */
    public function validate(array $values, array $configuration, array $datatypes): array;
}
