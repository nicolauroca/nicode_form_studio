<?php
declare(strict_types=1);

// Keep fixture registries local: withdrawing a provider must not change other tests.
(static function () use ($connection, $registry, $submissions, $search): void {
    $source = new class implements Nicode\FormStudio\Contract\DataSourceInterface {
        public int $calls = 0;
        public function id(): string { return 'fixture.historical'; }
        public function version(): string { return '1.0.0'; }
        public function metadata(): array { return ['cache' => false]; }
        public function validateConfiguration(array $configuration, string $path): array { return []; }
        public function options(array $configuration, array $inputs, array $trustedContext): array {
            $this->calls++;
            throw new RuntimeException('Historical reads must never query the live source.');
        }
    };
    $sources = new Nicode\FormStudio\Registry\DataSourceRegistry(); $sources->register($source);
    $makeCompiler = static fn ($sources) => new Nicode\FormStudio\Compiler\FormCompiler($registry, new Nicode\FormStudio\Registry\ProviderRegistry(), $sources, new Nicode\FormStudio\Registry\ProviderRegistry());
    $forms = new Nicode\FormStudio\Infrastructure\Database\FormRepository($connection, $makeCompiler($sources));
    $form = $forms->create('Historical provider', 'history-provider-' . bin2hex(random_bytes(6)), 1);
    $draft = $forms->draft($form); $field = Nicode\FormStudio\Domain\Uuid::create();
    $draft['elements'] = [['uuid' => $field, 'type' => 'field']];
    $draft['fields'] = [['uuid' => $field, 'name' => 'choice', 'type' => 'select', 'config' => ['label' => 'Original field label'], 'source' => ['type' => $source->id(), 'config' => []]]];
    $revision = $forms->saveDraft($form, 0, $draft, 1); $version = $forms->publish($form, $revision, 1);
    $labels = [$field => ['retired-code' => 'Original retired option <label>']];
    $response = $submissions->persist($form, $version, $forms->version($form, $version), [$field => 'retired-code'], hash('sha256', random_bytes(32)), ['option_labels' => $labels]);
    $authorize = static fn (int $actor, ?int $form, string $permission): bool => $actor === 1;
    $reader = new Nicode\FormStudio\Application\SubmissionReader($submissions, $forms, $connection, $authorize);
    $before = $reader->read($form, $response->id, 1);
    $missingCompiler = $makeCompiler(new Nicode\FormStudio\Registry\DataSourceRegistry());
    if ($missingCompiler->compile($draft)->successful()) { throw new RuntimeException('Missing-provider fixture still compiles.'); }
    $withoutProvider = new Nicode\FormStudio\Infrastructure\Database\FormRepository($connection, $missingCompiler);
    $reader = new Nicode\FormStudio\Application\SubmissionReader($submissions, $withoutProvider, $connection, $authorize);
    $after = $reader->read($form, $response->id, 1);
    if ($after !== $before || $after['values'] !== [$field => 'retired-code'] || $after['option_labels'] !== $labels || $after['labels'] !== [$field => 'Original field label'] || $after['form_version_id'] !== $version) { throw new RuntimeException('Unavailable provider changed historical response interpretation.'); }
    $explorer = new Nicode\FormStudio\Application\SubmissionExplorer($connection, $withoutProvider, $search, $reader, $authorize, static fn (): array => [], $registry);
    $detail = $explorer->detail(1, $form, $response->id);
    $export = $reader->readBatch($form, [$response->id], 1, 'export')[$response->id];
    foreach (['values', 'labels', 'option_labels', 'form_version_id'] as $key) {
        if ($detail[$key] !== $before[$key] || $export[$key] !== $before[$key]) { throw new RuntimeException('Unavailable provider changed detail/export projection.'); }
    }
    if ($detail['layout'] !== $before['layout'] || $source->calls !== 0) { throw new RuntimeException('Historical detail queried a provider or changed its layout.'); }
    try { $reader->read($form, $response->id, 2); throw new RuntimeException('Missing provider bypassed response ACL.'); } catch (DomainException) {}
    echo "Historical unavailable provider: exact values, field/option labels, version and layout preserved in reader/detail/export; no live source calls; ACL enforced.\n";
})();
