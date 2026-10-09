<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Security;

/** Safe public category; never carries the challenge or provider credentials. */
final class CaptchaException extends \RuntimeException
{
    public function __construct(public readonly string $category)
    {
        parent::__construct($category);
    }
}
