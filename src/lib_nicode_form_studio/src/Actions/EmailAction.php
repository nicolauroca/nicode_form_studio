<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Actions;

use Nicode\FormStudio\Contract\ActionInterface;
use Nicode\FormStudio\Contract\MailTransportInterface;
use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Domain\Uuid;

final readonly class EmailAction implements ActionInterface
{
    public function __construct(private MailTransportInterface $mail, private TokenTemplate $templates, private bool $autoresponse = false, private ?\Nicode\FormStudio\Contract\MailAttachmentResolverInterface $attachments = null) {}
    public function id(): string { return $this->autoresponse ? 'email_autoresponse' : 'email_notification'; }
    public function version(): string { return '1.0.0'; }
    public const ROW_SELECTIONS = ['first_nonempty', 'last_nonempty', 'unique'];
    public function metadata(): array
    {
        return ['id' => $this->id(), 'version' => $this->version(), 'failure_policies' => ['blocking', 'non_blocking'], 'retry' => 'definite_failure_only', 'configuration_schema' => ['type' => 'object', 'properties' => [
            ...($this->autoresponse ? ['email_field' => ['type' => 'string', 'field_type' => 'email']] : ['to' => ['type' => 'array', 'items' => ['type' => 'string']]]),
            'cc' => ['type' => 'array', 'items' => ['type' => 'string']], 'bcc' => ['type' => 'array', 'items' => ['type' => 'string']],
            'reply_to_field' => ['type' => 'string', 'field_type' => 'email'], 'subject' => ['type' => 'string'],
            ...($this->autoresponse ? ['email_field_selection' => ['type' => 'string', 'enum' => self::ROW_SELECTIONS, 'row_selection' => true]] : []),
            'reply_to_field_selection' => ['type' => 'string', 'enum' => self::ROW_SELECTIONS, 'row_selection' => true],
            'email_format' => ['type' => 'string', 'enum' => ['text', 'html']],
            'body_text' => ['type' => 'string', 'multiline' => true], 'body_html' => ['type' => 'string', 'multiline' => true],
            'attachment_fields' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 20, 'uniqueItems' => true, 'attachment_fields' => true],
        ]]];
    }
    public function validateConfiguration(array $configuration, string $path): array
    {
        $errors = [];
        if (isset($configuration['email_format']) && !in_array($configuration['email_format'], ['text', 'html'], true)) { $errors[] = new Diagnostic('action.email_format', $path . '/email_format', 'Choose plain text or HTML.'); }
        if (($configuration['email_format'] ?? null) === 'html' && (!is_string($configuration['body_html'] ?? null) || trim($configuration['body_html']) === '')) { $errors[] = new Diagnostic('action.body', $path . '/body_html', 'HTML content is required.'); }
        if (array_key_exists('attachment_fields', $configuration)) {
            $selection = $configuration['attachment_fields'];
            if (!is_array($selection) || !array_is_list($selection) || count($selection) > 20 || array_filter($selection, static fn($uuid): bool => !Uuid::valid($uuid)) !== [] || count(array_unique($selection)) !== count($selection)) {
                $errors[] = new Diagnostic('action.attachments', $path . '/attachment_fields', 'Choose up to 20 distinct file field UUIDs.');
            }
        }
        foreach (['email_field', 'reply_to_field'] as $key) {
            if (array_key_exists($key . '_selection', $configuration) && (!in_array($configuration[$key . '_selection'], self::ROW_SELECTIONS, true) || !Uuid::valid($configuration[$key] ?? null))) {
                $errors[] = new Diagnostic('action.email_selection', $path . '/' . $key . '_selection', 'Choose a supported row selection and its email field.');
            }
        }
        if ($this->autoresponse) {
            if (!Uuid::valid($configuration['email_field'] ?? null)) { $errors[] = new Diagnostic('action.email_field', $path, 'Autoresponse requires an email field UUID.'); }
        } elseif (!is_array($configuration['to'] ?? null) || $configuration['to'] === []) { $errors[] = new Diagnostic('action.recipients', $path, 'At least one recipient is required.'); }
        foreach (['to', 'cc', 'bcc'] as $key) {
            if (isset($configuration[$key]) && (!is_array($configuration[$key]) || !array_is_list($configuration[$key]))) { $errors[] = new Diagnostic('action.addresses', "$path/$key", 'Expected recipient list.'); continue; }
            foreach ($configuration[$key] ?? [] as $email) { if (!MailMessage::validAddress($email)) { $errors[] = new Diagnostic('action.address', "$path/$key", 'Invalid mail address.'); } }
        }
        if (!is_string($configuration['subject'] ?? null) || preg_match('/[\x00-\x1f\x7f]/', $configuration['subject'])) { $errors[] = new Diagnostic('action.subject', $path, 'A safe mail subject is required.'); }
        if (!is_string($configuration['body_text'] ?? null) && !(($configuration['email_format'] ?? null) === 'html' && !isset($configuration['body_text']))) { $errors[] = new Diagnostic('action.body', $path, 'Plain text body is required.'); }
        if (isset($configuration['body_html']) && !is_string($configuration['body_html'])) { $errors[] = new Diagnostic('action.body', $path, 'HTML body must be text.'); }
        $pins = [];
        if (isset($configuration['template'])) { $pins[] = $configuration['template']; }
        if (isset($configuration['template_translations'])) {
            if (!is_array($configuration['template_translations'])) { $errors[] = new Diagnostic('action.template', $path, 'Invalid template references.'); }
            else { array_push($pins, ...array_values($configuration['template_translations'])); }
        }
        foreach ($pins as $pin) {
            if (!is_array($pin) || array_diff(array_keys($pin), ['uuid', 'revision', 'hash']) !== [] || !Uuid::valid($pin['uuid'] ?? null) || !is_int($pin['revision'] ?? null) || $pin['revision'] < 1 || !is_string($pin['hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $pin['hash']) !== 1) { $errors[] = new Diagnostic('action.template', $path, 'Invalid copied template provenance.'); }
        }
        return $errors;
    }
    public function execute(array $configuration, ActionContext $context): ActionOutcome
    {
        if ($this->validateConfiguration($configuration, '/action') !== []) { throw new ActionFailure('configuration_invalid'); }
        try {
            $tokens = $context->emailTokens();
            $recipient = $this->autoresponse ? [$this->emailField($configuration['email_field'], $context, $configuration['email_field_selection'] ?? null)] : $configuration['to'];
            $replyTo = isset($configuration['reply_to_field']) ? $this->emailField($configuration['reply_to_field'], $context, $configuration['reply_to_field_selection'] ?? null) : null;
            $html = ($configuration['email_format'] ?? null) !== 'text' && ($configuration['body_html'] ?? '') !== '' ? $this->templates->render($configuration['body_html'], $tokens, 'html') : null;
            $text = $this->templates->render($configuration['body_text'] ?? '', $tokens);
            if ($text === '' && $html !== null) {
                $plain = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html);
                $plain = preg_replace('/<br\s*\/?\s*>|<\/(?:p|div|h[1-6]|li|tr|pre)>/i', "\n", $plain);
                $text = trim(html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            $message = new MailMessage($recipient, $configuration['cc'] ?? [], $configuration['bcc'] ?? [], $replyTo,
                $this->templates->render($configuration['subject'], $tokens, 'header'),
                $text, $html,
                attachments: $this->resolveAttachments($configuration['attachment_fields'] ?? [], $context));
        } catch (ActionFailure $failure) { throw $failure; }
        catch (\Throwable) { throw new ActionFailure('mail_preparation_failed'); }
        // A transport may already have delivered before throwing; keep it outside preparation.
        $this->mail->send($message);
        return new ActionOutcome('mail_sent');
    }
    private function resolveAttachments(array $selection, ActionContext $context): array
    {
        if ($selection === []) { return []; }
        $resolver = $context->attachments ?? $this->attachments;
        if ($resolver === null) { throw new ActionFailure('mail_attachment_unavailable'); }
        try { return $resolver->resolve($selection, $context); }
        catch (\Throwable) { throw new ActionFailure('mail_attachment_unavailable'); }
    }
    private function emailField(string $uuid, ActionContext $context, ?string $selection = null): string
    {
        $fields = array_column($context->spec->toArray()['fields'], null, 'uuid');
        if (($fields[$uuid]['type'] ?? null) !== 'email') { throw new ActionFailure('email_field_invalid'); }
        $value = $context->values[$uuid] ?? null;
        if ($context->instances !== null) {
            $instances = new \Nicode\FormStudio\Domain\RepeatedInstances($context->spec->toArray()['elements'], $context->instances);
            if (!$instances->contains(new \Nicode\FormStudio\Domain\FieldAddress($uuid))) {
                if (!in_array($selection, self::ROW_SELECTIONS, true)) { throw new ActionFailure('email_selection_required'); }
                $values = [];
                foreach ($instances->addresses() as $address) {
                    if ($address->field !== $uuid) { continue; }
                    $candidate = $context->values[$address->key()] ?? null;
                    if ($candidate === null || $candidate === '') { continue; }
                    if (!MailMessage::validAddress($candidate)) { throw new ActionFailure('email_field_invalid'); }
                    $values[] = $candidate;
                }
                if ($selection === 'unique') {
                    $values = array_values(array_unique($values, SORT_STRING));
                    if (count($values) > 1) { throw new ActionFailure('email_selection_ambiguous'); }
                }
                $value = $values === [] ? null : ($selection === 'last_nonempty' ? $values[array_key_last($values)] : $values[0]);
            }
        }
        if (($fields[$uuid]['type'] ?? null) !== 'email' || !MailMessage::validAddress($value)) { throw new ActionFailure('email_field_invalid'); }
        return $value;
    }
}
