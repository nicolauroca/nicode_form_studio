<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Actions;

final class ActionFailure extends \RuntimeException
{
    public function __construct(public readonly string $resultCode, public readonly bool $unknownOutcome = false)
    {
        parent::__construct($resultCode);
    }
}
