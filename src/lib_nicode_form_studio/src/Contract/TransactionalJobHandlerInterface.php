<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Contract;

/** Database-only mutations and checkpoint must commit together. External effects must be queued. */
interface TransactionalJobHandlerInterface extends JobHandlerInterface {}
