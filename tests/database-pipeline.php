<?php
declare(strict_types=1);

$pipelineForm = $forms->create('Pipeline fixture', 'pipeline-' . bin2hex(random_bytes(5)), 1);
$pipelineDraft = $forms->draft($pipelineForm); $pipelineField = Nicode\FormStudio\Domain\Uuid::create();
$pipelineDraft['elements'] = [['uuid' => $pipelineField, 'type' => 'field', 'parent_uuid' => null]];
$pipelineDraft['fields'] = [['uuid' => $pipelineField, 'type' => 'text', 'name' => 'answer', 'config' => ['required' => true]]];
$pipelineDraft['privacy'] = ['store_ip' => true, 'store_user_agent' => true, 'retention' => ['action' => 'anonymize', 'amount' => 1, 'unit' => 'days']];
foreach (['captcha_error', 'captcha_required', 'captcha_unavailable', 'unexpected_error'] as $category) {
    $pipelineDraft['post_submit']['messages'][$category] = 'Base ' . $category . ' {{form.name}}';
    $pipelineDraft['translations']['es']['messages'][$category] = 'Traducido ' . $category . ' {{form.name}}';
}
$pipelineRevision = $forms->saveDraft($pipelineForm, 0, $pipelineDraft, 1); $pipelineVersion = $forms->publish($pipelineForm, $pipelineRevision, 1);
$captchaFixture = new class implements Nicode\FormStudio\Contract\CaptchaAdapterInterface {
    public int $checks = 0; public bool $valid = true; public string $failureCategory = 'captcha_error';
    public function available(): array { return ['test']; }
    public function assertAvailable(Nicode\FormStudio\Security\CaptchaPolicy $policy): void {}
    public function render(Nicode\FormStudio\Security\CaptchaPolicy $policy, string $instance): string { return ''; }
    public function validate(Nicode\FormStudio\Security\CaptchaPolicy $policy, ?string $answer): void {
        $this->checks++;
        if (!$this->valid) {
            if ($this->failureCategory === 'unexpected_error') { throw new RuntimeException('Private provider credentials must never be shown'); }
            throw new Nicode\FormStudio\Security\CaptchaException($this->failureCategory);
        }
    }
};
$conditionEngine = new Nicode\FormStudio\Rules\ConditionEvaluator(Nicode\FormStudio\Registry\RuleOperatorRegistry::core());
$ruleEngine = new Nicode\FormStudio\Rules\RuleEngine($conditionEngine, Nicode\FormStudio\Registry\RuleEffectRegistry::core(), $registry);
$validationEngine = new Nicode\FormStudio\Validation\ValidationEngine($registry, $ruleEngine, Nicode\FormStudio\Registry\ValidatorRegistry::core());
$pipelineActions = new Nicode\FormStudio\Actions\ActionEngine(new Nicode\FormStudio\Registry\ActionRegistry(), $runs, $conditionEngine, $registry);
$postSubmit = new Nicode\FormStudio\Submission\PostSubmit($conditionEngine, $registry, new Nicode\FormStudio\Actions\TokenTemplate(), new Nicode\FormStudio\Security\RedirectPolicy());
$attemptTokens = new Nicode\FormStudio\Security\AttemptTokens(random_bytes(32));
$metadataCollections = 0;
$requestContext = new Nicode\FormStudio\Submission\RequestContext(0, [1], 'en-GB', 'session-fixture', hash('sha256', random_bytes(32)), true, metadataProvider: static function () use (&$metadataCollections): array { $metadataCollections++; return ['ip' => '127.0.0.1', 'user_agent' => 'Pipeline test']; });
$pipeline = new Nicode\FormStudio\Application\SubmissionPipeline($forms, $submissions, new Nicode\FormStudio\Security\PublicAccess(), $attemptTokens, $captchaFixture, new Nicode\FormStudio\Infrastructure\Database\RateLimiter($connection), $validationEngine, $pipelineActions, $postSubmit, $storageProviders);
$token = $attemptTokens->issue($pipelineForm, $pipelineVersion, 'session-fixture:component');
$request = new Nicode\FormStudio\Submission\SubmitRequest($pipelineForm, $pipelineVersion, $token, [$pipelineField => ' accepted ', 'forged' => 'ignored']);
$response = $pipeline->submit($request, $requestContext);
if (!$response['accepted'] || !$response['processed'] || !isset($response['reference'], $response['next_attempt'])) { throw new RuntimeException('Full submission pipeline failed: ' . json_encode($response)); }
$persistedExpiry = $connection->row('SELECT received_at, expires_at FROM ' . $connection->table('submissions') . ' WHERE uuid = :uuid', [':uuid' => $response['reference']]);
if (abs(strtotime($persistedExpiry['expires_at'] . ' UTC') - strtotime($persistedExpiry['received_at'] . ' UTC') - 86400) > 5) { throw new RuntimeException('Ingress did not apply the published retention interval.'); }
$repeated = $pipeline->submit($request, $requestContext);
if (!($repeated['replayed'] ?? false) || $captchaFixture->checks !== 1 || $repeated['reference'] !== $response['reference']) { throw new RuntimeException('Technical replay repeated CAPTCHA or changed reference.'); }
$changed = new Nicode\FormStudio\Submission\SubmitRequest($pipelineForm, $pipelineVersion, $token, [$pipelineField => 'different']);
if ($pipeline->submit($changed, $requestContext)['category'] !== 'session_error') { throw new RuntimeException('Pipeline accepted changed attempt payload.'); }
$newToken = $attemptTokens->issue($pipelineForm, $pipelineVersion, 'session-fixture:component');
if ($pipeline->submit(new Nicode\FormStudio\Submission\SubmitRequest($pipelineForm, $pipelineVersion, $newToken, []), $requestContext)['category'] !== 'validation_error') { throw new RuntimeException('Pipeline accepted required-field bypass.'); }
$noCsrf = new Nicode\FormStudio\Submission\RequestContext(0, [1], 'en-GB', 'session-fixture', $requestContext->rateScope, false);
if ($pipeline->submit($request, $noCsrf)['category'] !== 'session_error') { throw new RuntimeException('Pipeline accepted CSRF bypass.'); }
$captchaFixture->valid = false;
foreach (['en-GB' => 'Base', 'es-ES' => 'Traducido'] as $locale => $prefix) {
    $failureContext = new Nicode\FormStudio\Submission\RequestContext(0, [1], $locale, 'session-fixture', $requestContext->rateScope, true);
    foreach (['captcha_error', 'captcha_required', 'captcha_unavailable', 'unexpected_error'] as $category) {
        $captchaFixture->failureCategory = $category;
        $rejected = $pipeline->submit(new Nicode\FormStudio\Submission\SubmitRequest($pipelineForm, $pipelineVersion, $newToken, [$pipelineField => 'answer']), $failureContext);
        if ($rejected['accepted'] !== false || $rejected['category'] !== $category || $rejected['message'] !== $prefix . ' ' . $category . ' Pipeline fixture' || str_contains(json_encode($rejected), 'Private provider credentials')) { throw new RuntimeException('Provider rejection lost its configured localized message or exposed exception details.'); }
    }
}
$captchaFixture->failureCategory = 'captcha_error';
if ((int) $connection->row('SELECT COUNT(*) AS total FROM ' . $connection->table('submissions') . ' WHERE form_id=:form', [':form' => $pipelineForm])['total'] !== 1) { throw new RuntimeException('Provider rejection stored a response.'); }
echo "Provider error messages: three CAPTCHA failures and unexpected provider exception preserve base/parent-locale messages, omit private details and create no responses.\n";
$connection->execute('UPDATE ' . $connection->table('forms') . " SET state = 'unpublished' WHERE id = :id", [':id' => $pipelineForm]);
if ($pipeline->submit($request, $requestContext)['category'] !== 'form_unavailable') { throw new RuntimeException('Old render token bypassed unpublication.'); }
if ($metadataCollections !== 1) { throw new RuntimeException('Rejected requests or replay collected request metadata.'); }
echo "Shared submission pipeline: availability, CSRF, CAPTCHA, validation, persistence and safe replay verified.\n";
$captchaFixture->valid = true;
foreach (['metadata', 'none'] as $mode) {
    $modeDraft = $forms->draft($pipelineForm); $modeDraft['persistence'] = ['mode' => $mode];
    $modeRevision = $forms->saveDraft($pipelineForm, (int) $forms->get($pipelineForm)['draft_revision'], $modeDraft, 1); $modeVersion = $forms->publish($pipelineForm, $modeRevision, 1);
    $modeToken = $attemptTokens->issue($pipelineForm, $modeVersion, 'session-fixture:component');
    $modeRequest = new Nicode\FormStudio\Submission\SubmitRequest($pipelineForm, $modeVersion, $modeToken, [$pipelineField => 'must-not-be-retained']);
    $modeResponse = $pipeline->submit($modeRequest, $requestContext);
    if (!$modeResponse['accepted']) { throw new RuntimeException('Non-retaining pipeline mode failed: ' . json_encode($modeResponse)); }
    $record = $connection->row('SELECT canonical_payload FROM ' . $connection->table('submissions') . ' WHERE uuid = :uuid', [':uuid' => $modeResponse['reference']]);
    if ($mode === 'none' && $record !== null) { throw new RuntimeException('No-store response row was retained.'); }
    if ($mode === 'metadata' && ($record === null || json_decode($record['canonical_payload'], true)['values'] !== [])) { throw new RuntimeException('Metadata mode retained response values.'); }
    $modeReplay = $pipeline->submit($modeRequest, $requestContext);
    if (!($modeReplay['replayed'] ?? false) || $modeReplay['reference'] !== $modeResponse['reference']) { throw new RuntimeException('Non-retaining replay failed.'); }
}
echo "Metadata and no-store modes omit answers while preserving safe technical replay.\n";
if ($metadataCollections !== 2) { throw new RuntimeException('No-storage mode or replay collected request metadata.'); }
require __DIR__ . '/database-nonretaining-actions.php';
require __DIR__ . '/database-persistence-messages.php';
