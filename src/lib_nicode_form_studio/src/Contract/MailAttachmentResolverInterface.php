<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Contract;

use Nicode\FormStudio\Actions\{ActionContext, MailAttachment};

interface MailAttachmentResolverInterface
{
    /** @return list<MailAttachment> Authorized bytes in canonical receipt order. */
    public function resolve(array $selectedFields, ActionContext $context): array;
}
