<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Contract;

interface FieldRendererInterface
{
    public function render(array $field, string $instance, mixed $value, array $errors = []): string;
}
