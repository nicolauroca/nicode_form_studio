<?php
declare(strict_types=1);

// Included by database.php against its isolated database and published fixtures.
$actionProvider = new class implements Nicode\FormStudio\Contract\ActionInterface {
    public array $calls = [];
    public bool $recover = false;
    public function id(): string { return 'test-action'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['retry' => 'definite_failure_only']; }
    public function validateConfiguration(array $configuration, string $path): array { return []; }
    public function execute(array $configuration, Nicode\FormStudio\Actions\ActionContext $context): Nicode\FormStudio\Actions\ActionOutcome {
        $key = $configuration['key']; $this->calls[$key] = ($this->calls[$key] ?? 0) + 1;
        if ($key === 'failed' && !$this->recover) { throw new Nicode\FormStudio\Actions\ActionFailure('test_failure'); }
        if ($key === 'unknown') { throw new Nicode\FormStudio\Actions\ActionFailure('test_unknown', true); }
        return new Nicode\FormStudio\Actions\ActionOutcome();
    }
};
$actionRegistry = new Nicode\FormStudio\Registry\ActionRegistry(); $actionRegistry->register($actionProvider);
$actionCompiler = new Nicode\FormStudio\Compiler\FormCompiler($registry, $actionRegistry, new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry());
$actionForms = new Nicode\FormStudio\Infrastructure\Database\FormRepository($connection, $actionCompiler);
$actionDraft = $forms->draft($id); $actionIds = [];
foreach (['success', 'failed', 'unknown', 'after'] as $order => $key) {
    $actionIds[$key] = Nicode\FormStudio\Domain\Uuid::create();
    $actionDraft['actions'][] = ['uuid' => $actionIds[$key], 'type' => 'test-action', 'order' => $order, 'enabled' => true, 'config' => ['key' => $key], 'failure_policy' => $key === 'failed' ? 'blocking' : 'non_blocking'];
}
$revision = $actionForms->saveDraft($id, (int) $forms->get($id)['draft_revision'], $actionDraft, 1);
$actionVersion = $actionForms->publish($id, $revision, 1); $actionSpec = $actionForms->version($id, $actionVersion);
$actionSubmission = $submissions->persist($id, $actionVersion, $actionSpec, [$uuid => 'action test'], hash('sha256', random_bytes(32)));
$actionContext = new Nicode\FormStudio\Actions\ActionContext($actionSpec, [$uuid => 'action test'], $actionSubmission->uuid, gmdate(DATE_ATOM));
$runs = new Nicode\FormStudio\Infrastructure\Database\ActionRunRepository($connection);
$engine = new Nicode\FormStudio\Actions\ActionEngine($actionRegistry, $runs, new Nicode\FormStudio\Rules\ConditionEvaluator(Nicode\FormStudio\Registry\RuleOperatorRegistry::core()), $registry);
$result = $engine->execute($actionSubmission->id, $actionContext);
if ($result['status'] !== 'blocking_failure' || $actionProvider->calls !== ['success' => 1, 'failed' => 1]) { throw new RuntimeException('Blocking action order failed.'); }
$engine->execute($actionSubmission->id, $actionContext);
if ($actionProvider->calls !== ['success' => 1, 'failed' => 1]) { throw new RuntimeException('Replay repeated action.'); }
$actionProvider->recover = true;
$result = $engine->execute($actionSubmission->id, $actionContext, true);
if ($result['status'] !== 'partial_failure' || $actionProvider->calls !== ['success' => 1, 'failed' => 2, 'unknown' => 1, 'after' => 1]) { throw new RuntimeException('Selective action retry failed.'); }
$engine->execute($actionSubmission->id, $actionContext, true);
if ($actionProvider->calls['unknown'] !== 1 || $actionProvider->calls['success'] !== 1 || $actionProvider->calls['failed'] !== 2) { throw new RuntimeException('Retry repeated uncertain or completed side effects.'); }
try { $engine->execute($submission->id, $actionContext); throw new RuntimeException('Mismatched action snapshot accepted.'); }
catch (DomainException) { echo "Action snapshot ownership verified.\n"; }
echo "Action ordering, blocking failures, replay and selective retries verified.\n";
