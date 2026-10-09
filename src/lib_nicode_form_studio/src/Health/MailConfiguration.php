<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Health;

use Nicode\FormStudio\Actions\MailMessage;

/** Static configuration checks only; never connects, sends or returns settings. */
final class MailConfiguration
{
    public static function inspect(array $config): array
    {
        $failure = static fn (string $reason): array => ['status' => 'not_configured', 'reason' => $reason];
        if (!filter_var($config['mailonline'] ?? true, FILTER_VALIDATE_BOOLEAN)) { return $failure('mail_disabled'); }
        if (!MailMessage::validAddress($config['mailfrom'] ?? null)) { return $failure('mail_sender_invalid'); }
        $transport = $config['mailer'] ?? 'mail';
        if (!in_array($transport, ['mail', 'sendmail', 'smtp'], true)) { return $failure('mail_transport_invalid'); }
        if ($transport === 'smtp') {
            $host = $config['smtphost'] ?? null;
            if (!is_string($host) || trim($host) === '' || preg_match('/[\x00-\x1f\x7f]/', $host)) { return $failure('mail_smtp_host_invalid'); }
            $port = $config['smtpport'] ?? null;
            if ((!is_int($port) && !is_string($port)) || !preg_match('/^[0-9]{1,5}$/D', (string) $port) || (int) $port < 1 || (int) $port > 65535) { return $failure('mail_smtp_port_invalid'); }
            if (!in_array($config['smtpsecure'] ?? 'none', ['', 'none', 'ssl', 'tls'], true)) { return $failure('mail_smtp_security_invalid'); }
            $auth = filter_var($config['smtpauth'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($auth === null) { return $failure('mail_smtp_auth_invalid'); }
            if ($auth) {
                foreach (['smtpuser', 'smtppass'] as $key) {
                    if (!is_string($config[$key] ?? null) || $config[$key] === '') { return $failure('mail_smtp_auth_invalid'); }
                }
            }
        }
        return ['status' => 'ok', 'reason' => 'mail_delivery_unverified'];
    }
}
