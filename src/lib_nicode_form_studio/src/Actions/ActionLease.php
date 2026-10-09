<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Actions;

final readonly class ActionLease
{
    public function __construct(public int $id, public string $token, public int $attempt) {}
}
