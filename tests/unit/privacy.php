<?php
declare(strict_types=1);

test('calendar retention clamps month and leap-year boundaries without rollover', function (): void {
    $monthly = new Nicode\FormStudio\Privacy\RetentionPolicy('delete', 1, 'months');
    same('2024-02-29 12:00:00', $monthly->expiresAt(strtotime('2024-01-31 12:00:00 UTC')));
    $annual = new Nicode\FormStudio\Privacy\RetentionPolicy('anonymize', 1, 'years');
    same('2025-02-28 12:00:00', $annual->expiresAt(strtotime('2024-02-29 12:00:00 UTC')));
    same(null, (new Nicode\FormStudio\Privacy\RetentionPolicy())->expiresAt(time()));
});
test('compiler rejects malformed runtime policies before publication', function (): void {
    foreach ([['security' => 'bad'], ['security' => ['rate_limit' => '30']], ['security' => ['captcha' => ['mode' => 'provider']]], ['privacy' => ['retention' => ['action' => 'delete', 'amount' => -1]]], ['post_submit' => ['messages' => ['success' => '{{secret}}']]]] as $override) {
        same(false, compiler()->compile(array_replace(definition(), $override))->successful());
    }
});

test('audit projection exposes numeric reveal counts without request values', function (): void {
    same(['fields' => 2, 'request_metadata_items' => 1], Nicode\FormStudio\Application\AuditLog::details(json_encode(['fields' => 2, 'request_metadata_items' => 1, 'ip' => '127.0.0.1', 'user_agent' => 'private', 'values' => ['secret']], JSON_THROW_ON_ERROR)));
    foreach ([-1, '2', 1.5, null, ['ip' => 'private']] as $invalid) {
        same([], Nicode\FormStudio\Application\AuditLog::details(json_encode(['request_metadata_items' => $invalid], JSON_THROW_ON_ERROR)));
    }
});
