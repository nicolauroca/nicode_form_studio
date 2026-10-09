<?php
declare(strict_types=1);
$dynamicProvider = new class implements Nicode\FormStudio\Contract\DataSourceInterface {
    public int $calls = 0;
    public function id(): string { return 'fixture.dynamic'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['cache' => false, 'context_keys' => ['view_levels']]; }
    public function validateConfiguration(array $configuration, string $path): array { return []; }
    public function options(array $configuration, array $inputs, array $trustedContext): array {
        $this->calls++;
        return in_array(1, $trustedContext['view_levels'] ?? [], true) && in_array('ES', $inputs, true) ? [['value' => 'MD', 'label' => 'Madrid', 'private' => 'never-output']] : [];
    }
};
$dynamicSources = new Nicode\FormStudio\Registry\DataSourceRegistry(); $dynamicSources->register($dynamicProvider);
$dynamicCompiler = new Nicode\FormStudio\Compiler\FormCompiler($registry, new Nicode\FormStudio\Registry\ProviderRegistry(), $dynamicSources, new Nicode\FormStudio\Registry\ProviderRegistry());
$dynamicForms = new Nicode\FormStudio\Infrastructure\Database\FormRepository($connection, $dynamicCompiler);
$dynamicRules = new Nicode\FormStudio\Rules\RuleEngine(new Nicode\FormStudio\Rules\ConditionEvaluator(Nicode\FormStudio\Registry\RuleOperatorRegistry::core()), Nicode\FormStudio\Registry\RuleEffectRegistry::core(), $registry, options: new Nicode\FormStudio\DataSource\OptionResolver($dynamicSources, new Nicode\FormStudio\DataSource\RequestCache()));
$dynamicDependencies = new Nicode\FormStudio\Registry\ProviderDependencies(['fields' => $registry, 'sources' => $dynamicSources]);
$dynamicValidation = new Nicode\FormStudio\Validation\ValidationEngine($registry, $dynamicRules);
$dynamicQueries = new Nicode\FormStudio\Application\FormOptions($dynamicForms, new Nicode\FormStudio\Security\PublicAccess(), $attemptTokens, $dynamicValidation, new Nicode\FormStudio\Infrastructure\Database\RateLimiter($connection), $dynamicDependencies);
$dynamicForm = $dynamicForms->create('Dynamic options query', 'dynamic-' . bin2hex(random_bytes(6)), 1);
$dynamicDraft = $dynamicForms->draft($dynamicForm); $parent = Nicode\FormStudio\Domain\Uuid::create(); $child = Nicode\FormStudio\Domain\Uuid::create();
$dynamicDraft['elements'] = [['uuid' => $parent, 'type' => 'field', 'parent_uuid' => null], ['uuid' => $child, 'type' => 'field', 'parent_uuid' => null]];
$dynamicDraft['fields'] = [['uuid' => $parent, 'name' => 'country', 'type' => 'text', 'config' => []], ['uuid' => $child, 'name' => 'province', 'type' => 'select', 'config' => ['required' => true], 'source' => ['type' => 'fixture.dynamic', 'dependencies' => [$parent], 'config' => ['secret' => 'never-output']]]];
$revision = $dynamicForms->saveDraft($dynamicForm, 0, $dynamicDraft, 1); $dynamicVersion = $dynamicForms->publish($dynamicForm, $revision, 1);
$dynamicContext = new Nicode\FormStudio\Submission\RequestContext(0, [1], 'en-GB', 'dynamic-session', hash('sha256', 'dynamic-' . $dynamicForm), true);
$dynamicToken = $attemptTokens->issue($dynamicForm, $dynamicVersion, 'dynamic-session:component');
$dynamicRequest = new Nicode\FormStudio\Submission\SubmitRequest($dynamicForm, $dynamicVersion, $dynamicToken, [$parent => 'ES']);
$dynamicOptions = $dynamicQueries->resolve($dynamicRequest, $dynamicContext);
if ($dynamicOptions !== [$child => [['value' => 'MD', 'label' => 'Madrid', 'enabled' => true]]] || str_contains(json_encode($dynamicOptions), 'never-output')) { throw new RuntimeException('Dynamic options were missing, blocked by incomplete required fields, or leaked private configuration.'); }
$dynamicRequestOther = new Nicode\FormStudio\Submission\SubmitRequest($dynamicForm, $dynamicVersion, $dynamicToken, [$parent => 'FR']);
if ($dynamicQueries->resolve($dynamicRequestOther, $dynamicContext) !== [$child => []]) { throw new RuntimeException('Dynamic source ignored declared inputs.'); }
$calls = $dynamicProvider->calls;
foreach ([
    new Nicode\FormStudio\Submission\RequestContext(0, [1], 'en-GB', 'dynamic-session', hash('sha256', 'dynamic-' . $dynamicForm), false),
    new Nicode\FormStudio\Submission\RequestContext(0, [1], 'en-GB', 'other-session', hash('sha256', 'dynamic-' . $dynamicForm), true),
    new Nicode\FormStudio\Submission\RequestContext(0, [1], 'en-GB', 'dynamic-session', hash('sha256', 'dynamic-' . $dynamicForm), true, 'module'),
] as $deniedContext) {
    try { $dynamicQueries->resolve($dynamicRequest, $deniedContext); throw new RuntimeException('Dynamic options bypassed session/channel/CSRF.'); } catch (Nicode\FormStudio\Submission\SubmissionFailure $error) { if ($error->category !== 'session_error') { throw $error; } }
}
if ($calls !== $dynamicProvider->calls) { throw new RuntimeException('Denied options request invoked provider.'); }
$scope = hash('sha256', 'options:' . $dynamicForm . ':' . $dynamicContext->rateScope);
$connection->execute('UPDATE ' . $connection->table('rate_limits') . ' SET attempts = 120 WHERE scope_hash = :scope', [':scope' => $scope]);
try { $dynamicQueries->resolve($dynamicRequest, $dynamicContext); throw new RuntimeException('Dynamic options rate limit bypassed.'); } catch (Nicode\FormStudio\Submission\SubmissionFailure $error) { if ($error->category !== 'rate_limited') { throw $error; } }
$dynamicForms->deactivate($dynamicForm, (int) $dynamicForms->get($dynamicForm)['draft_revision'], 1, 'unpublished');
try { $dynamicQueries->resolve($dynamicRequest, $dynamicContext); throw new RuntimeException('Unpublished options exposed.'); } catch (OutOfBoundsException) {}
echo "Dynamic options: session/channel/CSRF, publication, trusted inputs, private projection and separate rate limit verified.\n";
