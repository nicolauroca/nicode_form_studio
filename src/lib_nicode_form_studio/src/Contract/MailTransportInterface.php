<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Contract;

use Nicode\FormStudio\Actions\MailMessage;

interface MailTransportInterface
{
    public function send(MailMessage $message): void;
}
