<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Jobs;

final class LeaseLost extends \RuntimeException
{
    public function __construct() { parent::__construct('Job lease was cancelled, expired or claimed by another worker.'); }
}
