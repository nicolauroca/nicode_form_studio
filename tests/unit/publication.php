<?php
declare(strict_types=1);

test('publication checks private storage, mail configuration and secret references without exposing values', function (): void {
    $secrets = new class implements Nicode\FormStudio\Contract\SecretStoreInterface {
        public function get(string $reference): string { if ($reference === 'AVAILABLE') { return 'private-value'; } throw new RuntimeException('private-value'); }
    };
    $policy = new Nicode\FormStudio\Application\PublicationReadiness(new Nicode\FormStudio\Registry\StorageProviderRegistry(), $secrets, static fn (): bool => false);
    $draft = definition(); $draft['fields'][0]['type'] = 'file';
    $draft['actions'] = [['type' => 'email_notification', 'config' => []], ['type' => 'webhook', 'config' => ['signing_secret' => 'MISSING']]];
    try { $policy->assert(new Nicode\FormStudio\Domain\FormSpec($draft)); throw new RuntimeException('Incomplete deployment was publishable.'); }
    catch (Nicode\FormStudio\Compiler\CompilationException $error) {
        same(3, count($error->diagnostics)); same(false, str_contains(json_encode($error->diagnostics), 'private-value'));
    }
    $draft['fields'][0]['type'] = 'text'; $draft['actions'][0]['enabled'] = false; $draft['actions'][1]['config']['signing_secret'] = 'AVAILABLE';
    $policy->assert(new Nicode\FormStudio\Domain\FormSpec($draft));
    $unavailable = new Nicode\FormStudio\Registry\StorageProviderRegistry(); $unavailable->register(new Nicode\FormStudio\Storage\UnavailableStorage());
    $missingVolume = new Nicode\FormStudio\Application\PublicationReadiness($unavailable, $secrets, static fn (): bool => true);
    $missingVolume->assert(new Nicode\FormStudio\Domain\FormSpec($draft));
    $draft['fields'][0]['type'] = 'file';
    raises(Nicode\FormStudio\Compiler\CompilationException::class, fn () => $missingVolume->assert(new Nicode\FormStudio\Domain\FormSpec($draft)));
    raises(RuntimeException::class, fn () => $unavailable->get('local')->exists(str_repeat('a', 64)));
});

test('environment secret references cannot read arbitrary environment variables', function (): void {
    $reference = 'TEST_' . strtoupper(bin2hex(random_bytes(5))); $name = 'NICODE_FORMSTUDIO_SECRET_' . $reference;
    putenv($name . '=synthetic-secret');
    try {
        $secrets = new Nicode\FormStudio\Security\EnvironmentSecrets(); same('synthetic-secret', $secrets->get($reference));
        raises(DomainException::class, fn () => $secrets->get('../PATH'));
        raises(DomainException::class, fn () => $secrets->get('PATH'));
    } finally { putenv($name); }
});

test('publication requires a renderer only for field types used by the form', function (): void {
    $secrets = new Nicode\FormStudio\Security\EnvironmentSecrets();
    $renderers = new Nicode\FormStudio\Rendering\FieldRendererRegistry();
    $renderers->register('text', new Nicode\FormStudio\Rendering\CoreFieldRenderer());
    $policy = new Nicode\FormStudio\Application\PublicationReadiness(new Nicode\FormStudio\Registry\StorageProviderRegistry(), $secrets, static fn (): bool => true, $renderers);
    $draft = definition(); $policy->assert(new Nicode\FormStudio\Domain\FormSpec($draft));
    $draft['fields'][0]['type'] = 'custom-without-renderer';
    try { $policy->assert(new Nicode\FormStudio\Domain\FormSpec($draft)); throw new RuntimeException('Renderer-less custom field published.'); }
    catch (Nicode\FormStudio\Compiler\CompilationException $error) { same('publication.renderer', $error->diagnostics[0]->code); }
});
