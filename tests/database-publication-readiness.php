<?php
declare(strict_types=1);

$readyRenderers = new Nicode\FormStudio\Rendering\FieldRendererRegistry();
$readyRenderers->register('text', new Nicode\FormStudio\Rendering\CoreFieldRenderer());
$publicationServices = [];
foreach (['ready' => $readyRenderers, 'missing' => new Nicode\FormStudio\Rendering\FieldRendererRegistry()] as $key => $rendererRegistry) {
    $readiness = new Nicode\FormStudio\Application\PublicationReadiness(new Nicode\FormStudio\Registry\StorageProviderRegistry(), new Nicode\FormStudio\Security\EnvironmentSecrets(), static fn (): bool => true, $rendererRegistry);
    $publicationServices[$key] = new Nicode\FormStudio\Application\FormAdministration($forms, $connection, static fn (): bool => true, static function (): void {}, $adminCaptcha, $readiness);
}
$readyForm = $publicationServices['ready']->create('Readiness rollback', 'readiness-' . bin2hex(random_bytes(6)), 731);
$readyDraft = $forms->draft($readyForm); $readyField = Nicode\FormStudio\Domain\Uuid::create();
$readyDraft['elements'] = [['uuid' => $readyField, 'type' => 'field']];
$readyDraft['fields'] = [['uuid' => $readyField, 'name' => 'answer', 'type' => 'text', 'config' => ['label' => 'Original']]];
$readyRevision = $publicationServices['ready']->save($readyForm, 0, $readyDraft, 731);
$readyVersion = $publicationServices['ready']->publish($readyForm, $readyRevision, 731);
$readyHash = $forms->version($readyForm, $readyVersion)->hash;
$readyDraft['fields'][0]['config']['label'] = 'Corrected deployment';
$readyRevision = $publicationServices['ready']->save($readyForm, (int) $forms->get($readyForm)['draft_revision'], $readyDraft, 731);
$publicationState = static function () use ($connection, $forms, $readyForm): array {
    $state = ['form' => $forms->get($readyForm), 'draft' => $forms->draft($readyForm)];
    foreach (['form_versions', 'version_field_policy', 'audit_log'] as $table) {
        $state[$table] = $connection->rows('SELECT * FROM ' . $connection->table($table) . ' WHERE form_id = :form ORDER BY id', [':form' => $readyForm]);
    }
    return $state;
};
$beforeReadiness = $publicationState();
try {
    $publicationServices['missing']->publish($readyForm, $readyRevision, 731);
    throw new RuntimeException('A missing renderer was published.');
} catch (Nicode\FormStudio\Compiler\CompilationException $error) {
    if (count($error->diagnostics) !== 1 || $error->diagnostics[0]->code !== 'publication.renderer' || $error->diagnostics[0]->path !== '/fields/0') { throw new RuntimeException('Readiness failure lost its field diagnostic.'); }
}
if ($publicationState() !== $beforeReadiness) { throw new RuntimeException('Readiness rejection changed form, draft, history, policy or audit.'); }
$correctedVersion = $publicationServices['ready']->publish($readyForm, $readyRevision, 731);
$correctedState = $publicationState();
if ((int) $correctedState['form']['published_version_id'] !== $correctedVersion || count($correctedState['form_versions']) !== 2 || count($correctedState['version_field_policy']) !== 2 || count($correctedState['audit_log']) !== count($beforeReadiness['audit_log']) + 1 || $forms->version($readyForm, $readyVersion)->hash !== $readyHash) { throw new RuntimeException('Corrected readiness did not activate exactly one immutable version.'); }
try {
    $publicationServices['ready']->publish($readyForm, $readyRevision, 731);
    throw new RuntimeException('A stale publisher created a competing version.');
} catch (Nicode\FormStudio\Domain\ConcurrentEdit) {}
if ($publicationState() !== $correctedState) { throw new RuntimeException('Stale publication changed the accepted state.'); }
echo "Publication readiness: failed renderer preserves complete state; corrected retry activates once and stale retry changes nothing.\n";
$readyDraft['fields'][0]['config']['label'] = '';
$warningRevision = $publicationServices['ready']->save($readyForm, (int) $correctedState['form']['draft_revision'], $readyDraft, 731);
$warningPublication = $publicationServices['ready']->publishDetailed($readyForm, $warningRevision, 731);
if (array_column($warningPublication['diagnostics'], 'code') !== ['a11y.field_label'] || $warningPublication['diagnostics'][0]->severity !== 'WARNING' || (int) $forms->get($readyForm)['published_version_id'] !== $warningPublication['version_id']) { throw new RuntimeException('Nonblocking publication warning was lost or prevented activation.'); }
echo "Publication advisories: warning belongs to the successfully activated compilation.\n";

$browserFields = new Nicode\FormStudio\Registry\FieldTypeRegistry();
$browserFields->register(new class implements Nicode\FormStudio\Contract\FieldTypeInterface, Nicode\FormStudio\Contract\BrowserProviderInterface {
    public function id(): string { return 'publication_widget'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['id' => $this->id(), 'version' => $this->version(), 'datatype' => 'text', 'operators' => ['equals'], 'assets' => []]; }
    public function validateConfiguration(array $configuration, string $path): array { return []; }
    public function normalize(mixed $value, array $configuration): mixed { return $value; }
    public function validate(mixed $value, array $configuration): array { return []; }
    public function serialize(mixed $value): mixed { return $value; }
    public function indexType(): ?string { return 'keyword'; }
    public function multiple(): bool { return false; }
    public function browser(): array { return ['asset' => 'publication.widget', 'styles' => ['publication.widget.style'], 'public_config' => []]; }
});
$browserCompiler = new Nicode\FormStudio\Compiler\FormCompiler($browserFields, new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry());
$browserForms = new Nicode\FormStudio\Infrastructure\Database\FormRepository($connection, $browserCompiler);
$browserRenderers = new Nicode\FormStudio\Rendering\FieldRendererRegistry();
$browserRenderers->register('publication_widget', new Nicode\FormStudio\Rendering\CoreFieldRenderer());
$browserServices = [];
foreach (['ready', 'missing_script', 'missing_style'] as $availability) {
    $browserProviders = new Nicode\FormStudio\Rendering\BrowserProviders(['fields' => $browserFields], static function (string $asset, string $kind) use ($availability): string {
        if ($availability === 'missing_' . $kind) { throw new RuntimeException('private deployment path must not escape'); }
        return $kind === 'script' ? '/installed/publication-widget.js' : '/installed/publication-widget.css';
    });
    $readiness = new Nicode\FormStudio\Application\PublicationReadiness(new Nicode\FormStudio\Registry\StorageProviderRegistry(), new Nicode\FormStudio\Security\EnvironmentSecrets(), static fn (): bool => true, $browserRenderers, new Nicode\FormStudio\Rendering\PublicSpec($browserFields, $browserProviders));
    $browserServices[$availability] = new Nicode\FormStudio\Application\FormAdministration($browserForms, $connection, static fn (): bool => true, static function (): void {}, $adminCaptcha, $readiness);
}
$browserForm = $browserServices['ready']->create('Browser readiness rollback', 'browser-readiness-' . bin2hex(random_bytes(6)), 731);
$browserDraft = $browserForms->draft($browserForm); $browserField = Nicode\FormStudio\Domain\Uuid::create();
$browserDraft['elements'] = [['uuid' => $browserField, 'type' => 'field']];
$browserDraft['fields'] = [['uuid' => $browserField, 'name' => 'widget', 'type' => 'publication_widget', 'config' => ['label' => 'Original widget']]];
$browserRevision = $browserServices['ready']->save($browserForm, 0, $browserDraft, 731);
$browserVersion = $browserServices['ready']->publish($browserForm, $browserRevision, 731);
$browserHash = $browserForms->version($browserForm, $browserVersion)->hash;
$browserDraft['fields'][0]['config']['label'] = 'Updated widget';
$browserRevision = $browserServices['ready']->save($browserForm, (int) $browserForms->get($browserForm)['draft_revision'], $browserDraft, 731);
$browserState = static function () use ($connection, $browserForms, $browserForm): array {
    $state = ['form' => $browserForms->get($browserForm), 'draft' => $browserForms->draft($browserForm)];
    foreach (['form_versions', 'version_field_policy', 'audit_log'] as $table) { $state[$table] = $connection->rows('SELECT * FROM ' . $connection->table($table) . ' WHERE form_id = :form ORDER BY id', [':form' => $browserForm]); }
    return $state;
};
$beforeBrowserFailure = $browserState();
foreach (['missing_script', 'missing_style'] as $availability) {
    try { $browserServices[$availability]->publish($browserForm, $browserRevision, 731); throw new RuntimeException('Missing browser asset was publishable.'); }
    catch (Nicode\FormStudio\Compiler\CompilationException $error) {
        if (count($error->diagnostics) !== 1 || $error->diagnostics[0]->code !== 'publication.browser' || $error->diagnostics[0]->path !== '/provider_dependencies' || str_contains(json_encode($error->diagnostics), 'private deployment')) { throw new RuntimeException('Browser readiness lost its safe diagnostic.'); }
    }
    if ($browserState() !== $beforeBrowserFailure) { throw new RuntimeException('Missing browser asset mutated publication state.'); }
}
$browserCorrectedVersion = $browserServices['ready']->publish($browserForm, $browserRevision, 731);
$afterBrowserCorrection = $browserState();
if ((int) $afterBrowserCorrection['form']['published_version_id'] !== $browserCorrectedVersion || count($afterBrowserCorrection['form_versions']) !== 2 || count($afterBrowserCorrection['version_field_policy']) !== 2 || count($afterBrowserCorrection['audit_log']) !== count($beforeBrowserFailure['audit_log']) + 1 || $browserForms->version($browserForm, $browserVersion)->hash !== $browserHash) { throw new RuntimeException('Restored browser assets did not activate exactly one immutable version.'); }
echo "Publication browser readiness: missing script/style preserves complete state and safe diagnostics; restored assets activate once.\n";
