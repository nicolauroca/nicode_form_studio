<?php
declare(strict_types=1);

test('mail health checks SMTP prerequisites without disclosing settings', function (): void {
    $config = ['mailonline' => true, 'mailfrom' => 'sender@example.test', 'mailer' => 'smtp', 'smtphost' => 'private.example.test', 'smtpport' => '587', 'smtpsecure' => 'tls', 'smtpauth' => '1', 'smtpuser' => 'private-user', 'smtppass' => 'private-password'];
    $inspect = Nicode\FormStudio\Health\MailConfiguration::inspect(...);
    same(['status' => 'ok', 'reason' => 'mail_delivery_unverified'], $inspect($config));
    foreach ([['mailonline', '0', 'mail_disabled'], ['mailfrom', 'invalid', 'mail_sender_invalid'], ['mailer', 'unknown', 'mail_transport_invalid'], ['smtphost', '', 'mail_smtp_host_invalid'], ['smtphost', "host\r\nsecret", 'mail_smtp_host_invalid'], ['smtpport', '0', 'mail_smtp_port_invalid'], ['smtpport', '65536', 'mail_smtp_port_invalid'], ['smtpport', '25oops', 'mail_smtp_port_invalid'], ['smtpport', true, 'mail_smtp_port_invalid'], ['smtpport', 25.5, 'mail_smtp_port_invalid'], ['smtpsecure', 'unknown', 'mail_smtp_security_invalid'], ['smtpauth', 'unknown', 'mail_smtp_auth_invalid'], ['smtpuser', '', 'mail_smtp_auth_invalid'], ['smtppass', '', 'mail_smtp_auth_invalid']] as [$key, $value, $reason]) {
        same(['status' => 'not_configured', 'reason' => $reason], $inspect(array_replace($config, [$key => $value])));
    }
    foreach (['mail', 'sendmail'] as $transport) { same('ok', $inspect(array_replace($config, ['mailer' => $transport, 'smtphost' => '', 'smtpport' => 0]))['status']); }
    foreach ([1, '65535'] as $port) { same('ok', $inspect(array_replace($config, ['smtpport' => $port]))['status']); }
    same('ok', $inspect(array_replace($config, ['smtpauth' => false, 'smtpuser' => '', 'smtppass' => '']))['status']);
});
