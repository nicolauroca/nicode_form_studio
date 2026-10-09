<?php
declare(strict_types=1);

(static function () use ($connection, $registry, $submissions, $runs, $conditionEngine, $validationEngine, $postSubmit, $attemptTokens, $captchaFixture, $storageProviders, $requestContext): void {
    $provider = new class($connection) implements Nicode\FormStudio\Contract\ActionInterface {
        public int $calls = 0;
        public int $submissionId = 0;
        public function __construct(private $db) {}
        public function id(): string { return 'fixture.nonretaining'; }
        public function version(): string { return '1.0.0'; }
        public function metadata(): array { return ['retry' => 'definite_failure_only']; }
        public function validateConfiguration(array $configuration, string $path): array { return []; }
        public function execute(array $configuration, Nicode\FormStudio\Actions\ActionContext $context): Nicode\FormStudio\Actions\ActionOutcome {
            $this->calls++;
            $values = $context->values;
            if (count($values) !== 1 || !str_starts_with(reset($values), 'private-answer-')) { throw new LogicException('Action lost its non-retained input.'); }
            $row = $this->db->row('SELECT id, canonical_payload FROM ' . $this->db->table('submissions') . ' WHERE uuid=:uuid', [':uuid' => $context->reference]);
            if ($row === null || json_decode($row['canonical_payload'], true, flags: JSON_THROW_ON_ERROR)['values'] !== []) { throw new LogicException('Non-retaining action had stored answers while executing.'); }
            $this->submissionId = (int) $row['id'];
            if ($configuration['outcome'] === 'definite') { throw new Nicode\FormStudio\Actions\ActionFailure('fixture_failed'); }
            if ($configuration['outcome'] === 'unknown') { throw new Nicode\FormStudio\Actions\ActionFailure('fixture_unknown', true); }
            if ($configuration['outcome'] === 'unexpected') { throw new RuntimeException(reset($values)); }
            return new Nicode\FormStudio\Actions\ActionOutcome('fixture_success');
        }
    };
    $actions = new Nicode\FormStudio\Registry\ActionRegistry(); $actions->register($provider);
    $compiler = new Nicode\FormStudio\Compiler\FormCompiler($registry, $actions, new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry());
    $forms = new Nicode\FormStudio\Infrastructure\Database\FormRepository($connection, $compiler);
    $engine = new Nicode\FormStudio\Actions\ActionEngine($actions, $runs, $conditionEngine, $registry);
    $pipeline = new Nicode\FormStudio\Application\SubmissionPipeline($forms, $submissions, new Nicode\FormStudio\Security\PublicAccess(), $attemptTokens, $captchaFixture, new Nicode\FormStudio\Infrastructure\Database\RateLimiter($connection), $validationEngine, $engine, $postSubmit, $storageProviders);
    foreach (['metadata', 'none'] as $mode) {
        foreach ([['success', 'non_blocking', 'success'], ['definite', 'blocking', 'action_blocking_failure'], ['definite', 'non_blocking', 'action_partial_failure'], ['unknown', 'non_blocking', 'action_partial_failure'], ['unexpected', 'blocking', 'action_blocking_failure']] as [$outcome, $policy, $category]) {
            $form = $forms->create('Non-retaining outcomes', 'nonretaining-' . bin2hex(random_bytes(6)), 1);
            $draft = $forms->draft($form); $field = Nicode\FormStudio\Domain\Uuid::create(); $action = Nicode\FormStudio\Domain\Uuid::create();
            $draft['elements'] = [['uuid' => $field, 'type' => 'field']];
            $draft['fields'] = [['uuid' => $field, 'name' => 'answer', 'type' => 'text', 'index' => true, 'config' => ['max_length' => 255]]];
            $draft['persistence']['mode'] = $mode;
            foreach (['success', 'action_partial_failure', 'action_blocking_failure', 'processing_pending'] as $messageCategory) {
                $draft['post_submit']['messages'][$messageCategory] = 'Configured ' . $messageCategory . ' {{submission.reference}}';
            }
            $draft['actions'] = [['uuid' => $action, 'type' => $provider->id(), 'failure_policy' => $policy, 'config' => ['outcome' => $outcome]]];
            $revision = $forms->saveDraft($form, 0, $draft, 1); $version = $forms->publish($form, $revision, 1);
            $marker = 'private-answer-' . bin2hex(random_bytes(16));
            $token = $attemptTokens->issue($form, $version, 'session-fixture:component');
            $request = new Nicode\FormStudio\Submission\SubmitRequest($form, $version, $token, [$field => $marker]);
            $calls = $provider->calls; $response = $pipeline->submit($request, $requestContext);
            if (!($response['accepted'] ?? false) || $response['category'] !== $category || $provider->calls !== $calls + 1) { throw new RuntimeException('Non-retaining outcome did not complete: ' . $mode . '/' . $outcome); }
            if ($response['message'] !== 'Configured ' . $category . ' ' . $response['reference']) { throw new RuntimeException('Action outcome lost its configured message or reference token.'); }
            $rows = $connection->rows('SELECT * FROM ' . $connection->table('submissions') . ' WHERE form_id=:form', [':form' => $form]);
            $attempts = $connection->rows('SELECT * FROM ' . $connection->table('attempts') . ' WHERE form_id=:form', [':form' => $form]);
            if (count($rows) !== ($mode === 'none' ? 0 : 1) || count($attempts) !== 1 || $attempts[0]['state'] !== ($mode === 'none' ? 'ephemeral_completed' : 'completed')) { throw new RuntimeException('Non-retaining outcome left the wrong response/attempt lifetime.'); }
            if (str_contains(json_encode([$rows, $attempts, $response], JSON_THROW_ON_ERROR), $marker)) { throw new RuntimeException('Answer or thrown exception leaked into replay/persistence/response.'); }
            if ($connection->rows('SELECT id FROM ' . $connection->table('submission_index') . ' WHERE form_id=:form', [':form' => $form]) !== []) { throw new RuntimeException('Non-retaining answer entered the search index.'); }
            $runRows = $connection->rows('SELECT * FROM ' . $connection->table('action_runs') . ' WHERE submission_id=:id', [':id' => $provider->submissionId]);
            if (count($runRows) !== ($mode === 'none' ? 0 : 1) || str_contains(json_encode($runRows, JSON_THROW_ON_ERROR), $marker)) { throw new RuntimeException('Action history retained private data or survived no-store completion.'); }
            $replay = $pipeline->submit($request, $requestContext);
            if (!($replay['replayed'] ?? false) || $replay['reference'] !== $response['reference'] || $replay['category'] !== $category || $provider->calls !== $calls + 1) { throw new RuntimeException('Non-retaining retry repeated an action or changed its result.'); }
            if ($replay['message'] !== $response['message']) { throw new RuntimeException('Action replay changed the stored configured message.'); }
        }
        // Reproduce the durable boundaries of a stopped worker: persisted input
        // metadata plus a claimed action, then the same request before/after expiry.
        $draft['actions'][0]['config']['outcome'] = 'success';
        $revision = $forms->saveDraft($form, (int) $forms->get($form)['draft_revision'], $draft, 1);
        $version = $forms->publish($form, $revision, 1); $spec = $forms->version($form, $version);
        $token = $attemptTokens->issue($form, $version, 'session-fixture:component');
        $hash = $attemptTokens->verify($token, $form, $version, 'session-fixture:component');
        $marker = 'private-answer-' . bin2hex(random_bytes(16));
        $persisted = $submissions->persist($form, $version, $spec, [$field => $marker], $hash, ['channel' => 'component', 'locale' => 'en-GB']);
        $lease = $runs->claim($persisted->id, $action, $provider->id());
        if ($lease === null) { throw new RuntimeException('Interrupted-action fixture did not obtain a lease.'); }
        $request = new Nicode\FormStudio\Submission\SubmitRequest($form, $version, $token, [$field => $marker]);
        $calls = $provider->calls; $pending = $pipeline->submit($request, $requestContext);
        if (($pending['category'] ?? '') !== 'processing_pending' || $provider->calls !== $calls || $submissions->response($form, $hash) !== null) { throw new RuntimeException('Live action lease was replayed or prematurely completed.'); }
        if ($pending['message'] !== 'Configured processing_pending ' . $persisted->uuid) { throw new RuntimeException('Pending action lost its configured message.'); }
        $connection->execute('UPDATE ' . $connection->table('action_runs') . ' SET lease_until=:expired WHERE id=:id', [':expired' => gmdate('Y-m-d H:i:s', time() - 1), ':id' => $lease->id]);
        $recovered = $pipeline->submit($request, $requestContext);
        if (($recovered['category'] ?? '') !== 'action_blocking_failure' || $provider->calls !== $calls) { throw new RuntimeException('Expired uncertain action was re-executed or reported successful.'); }
        if ($recovered['message'] !== 'Configured action_blocking_failure ' . $persisted->uuid) { throw new RuntimeException('Interrupted action recovery lost its configured failure message.'); }
        try { $runs->finish($lease, 'succeeded', 'late_worker'); throw new LogicException('Stale action owner overwrote recovery.'); } catch (DomainException) {}
        $row = $connection->row('SELECT canonical_payload FROM ' . $connection->table('submissions') . ' WHERE id=:id', [':id' => $persisted->id]);
        $latest = $runs->latest($persisted->id, $action);
        if ($mode === 'none' && ($row !== null || $latest !== null)) { throw new RuntimeException('Recovered no-store action retained its response/history.'); }
        if ($mode === 'metadata' && ($row === null || $latest['state'] !== 'unknown' || $latest['result_code'] !== 'worker_interrupted')) { throw new RuntimeException('Recovered metadata action lost its safe unknown outcome.'); }
        $cached = $submissions->response($form, $hash);
        if ($cached === null || str_contains(json_encode([$cached, $row, $latest], JSON_THROW_ON_ERROR), $marker)) { throw new RuntimeException('Recovery lost its technical result or retained private input.'); }
        $again = $pipeline->submit($request, $requestContext);
        if (!($again['replayed'] ?? false) || $again['category'] !== 'action_blocking_failure' || $provider->calls !== $calls) { throw new RuntimeException('Recovered technical replay repeated an uncertain effect.'); }
    }
    echo "Non-retaining action outcomes: ten mode/outcome cases, in-memory input, empty persisted answers, safe exception/replay data, no index and one execution passed.\n";
    echo "Non-retaining interrupted actions: live lease pending, expired lease unknown, stale owner fenced, no-store completion erasure and safe replay without effect repetition passed.\n";
})();
