<?php
declare(strict_types=1);

use Nicode\FormStudio\Security\AttemptTokens;
use Nicode\FormStudio\Security\PublicAccess;

test('attempt tokens bind form version session elapsed time and expiration', function (): void {
    $now = 1800000000; $tokens = new AttemptTokens(str_repeat('k', 32), static function () use (&$now): int { return $now; });
    $token = $tokens->issue(12, 3, 'session');
    raises(DomainException::class, fn () => $tokens->verify($token, 12, 3, 'session', 3));
    $now += 3; same(hash('sha256', $token), $tokens->verify($token, 12, 3, 'session', 3));
    raises(DomainException::class, fn () => $tokens->verify($token, 12, 4, 'session'));
    raises(DomainException::class, fn () => $tokens->verify($token, 12, 3, 'other-session'));
    raises(DomainException::class, fn () => $tokens->verify($token . 'x', 12, 3, 'session'));
    $now += 7197; raises(DomainException::class, fn () => $tokens->verify($token, 12, 3, 'session'));
});
test('public access enforces publication language ACL and both schedule boundaries', function (): void {
    $guard = new PublicAccess(); $now = 1800000000;
    $form = ['state' => 'published', 'published_version_id' => 1, 'access' => 2, 'language' => 'es-ES', 'publish_up' => gmdate('Y-m-d H:i:s', $now)];
    $guard->assert($form, [1, 2], 'es-ES', $now);
    raises(OutOfBoundsException::class, fn () => $guard->assert($form, [1], 'es-ES', $now));
    raises(OutOfBoundsException::class, fn () => $guard->assert($form, [2], 'en-GB', $now));
    raises(OutOfBoundsException::class, fn () => $guard->assert($form, [2], 'es-ES', $now - 1));
    $form['publish_down'] = gmdate('Y-m-d H:i:s', $now);
    raises(OutOfBoundsException::class, fn () => $guard->assert($form, [2], 'es-ES', $now));
    $form['publish_down'] = null; $form['state'] = 'unpublished';
    raises(OutOfBoundsException::class, fn () => $guard->assert($form, [2], 'es-ES', $now));
});
