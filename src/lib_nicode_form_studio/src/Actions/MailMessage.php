<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Actions;

final readonly class MailMessage
{
    public function __construct(public array $to, public array $cc, public array $bcc, public ?string $replyTo, public string $subject, public string $text, public ?string $html = null, public array $attachments = [])
    {
        if ($to === [] || preg_match('/[\x00-\x1f\x7f]/', $subject)) { throw new \InvalidArgumentException('Invalid mail subject or recipients.'); }
        foreach ([...$to, ...$cc, ...$bcc, ...($replyTo !== null ? [$replyTo] : [])] as $email) {
            if (!self::validAddress($email)) { throw new \InvalidArgumentException('Invalid mail address.'); }
        }
        if (!array_is_list($attachments) || count($attachments) > 20) { throw new \InvalidArgumentException('Invalid attachment list.'); }
        $bytes = 0;
        foreach ($attachments as $attachment) {
            if (!$attachment instanceof MailAttachment) { throw new \InvalidArgumentException('Expected authorized attachment bytes.'); }
            $bytes += strlen($attachment->bytes);
            if ($bytes > MailAttachment::MAXIMUM_BYTES) { throw new \LengthException('Combined attachments exceed mail size limit.'); }
        }
    }
    public static function validAddress(mixed $email): bool { return is_string($email) && preg_match('/[\x00-\x20\x7f]/', $email) === 0 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false; }
}
