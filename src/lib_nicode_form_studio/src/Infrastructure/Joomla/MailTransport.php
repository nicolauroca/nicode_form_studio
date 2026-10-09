<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Infrastructure\Joomla;

use Joomla\CMS\Mail\Mail;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Nicode\FormStudio\Actions\ActionFailure;
use Nicode\FormStudio\Actions\MailMessage;
use Nicode\FormStudio\Contract\MailTransportInterface;

final readonly class MailTransport implements MailTransportInterface
{
    public function __construct(private MailerFactoryInterface $factory, private string $sender, private string $senderName)
    {
        if (!MailMessage::validAddress($sender) || preg_match('/[\x00-\x1f\x7f]/', $senderName)) { throw new \InvalidArgumentException('Invalid configured mail sender.'); }
    }
    public function send(MailMessage $message): void
    {
        try {
            $mailer = $this->factory->createMailer();
            $check = static function (mixed $result): void {
                if ($result === false) { throw new ActionFailure('mail_preparation_failed'); }
            };
            $check($mailer->setSender($this->sender, $this->senderName));
            // Joomla/PHPMailer returns false for duplicates as well as rejected addresses.
            // Preserve its first-recipient-wins semantics across To/Cc/Bcc explicitly.
            $seen = [];
            foreach (['addRecipient' => $message->to, 'addCc' => $message->cc, 'addBcc' => $message->bcc] as $method => $recipients) {
                foreach ($recipients as $email) {
                    $key = strtolower($email);
                    if (isset($seen[$key])) { continue; }
                    $check($mailer->$method($email)); $seen[$key] = true;
                }
            }
            if ($message->replyTo !== null) { $check($mailer->addReplyTo($message->replyTo)); }
            $check($mailer->setSubject($message->subject));
            if ($message->html !== null) {
                if (!$mailer instanceof Mail) { throw new ActionFailure('mail_html_unsupported'); }
                $mailer->isHtml(true); $mailer->AltBody = $message->text; $check($mailer->setBody($message->html));
            } else { $check($mailer->setBody($message->text)); }
            if ($message->attachments !== [] && !$mailer instanceof Mail) { throw new ActionFailure('mail_attachments_unsupported'); }
            foreach ($message->attachments as $attachment) {
                $check($mailer->addStringAttachment($attachment->bytes, $attachment->name, 'base64', $attachment->mimeType, 'attachment'));
            }
        } catch (ActionFailure $failure) { throw $failure; }
        catch (\Throwable) { throw new ActionFailure('mail_preparation_failed'); }
        try {
            if ($mailer->send() === false) { throw new ActionFailure('mail_delivery_unknown', true); }
        } catch (\Joomla\CMS\Mail\Exception\MailDisabledException) {
            throw new ActionFailure('mail_disabled');
        } catch (\Throwable) { throw new ActionFailure('mail_delivery_unknown', true); }
    }
}
