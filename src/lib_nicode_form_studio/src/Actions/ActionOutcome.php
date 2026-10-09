<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Actions;

final readonly class ActionOutcome
{
    public function __construct(public string $code = 'completed', public array $navigation = []) {}
}
