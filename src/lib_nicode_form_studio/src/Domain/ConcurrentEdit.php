<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Domain;

final class ConcurrentEdit extends \RuntimeException
{
    public function __construct() { parent::__construct('The draft changed. Reload and merge your changes before saving.'); }
}
