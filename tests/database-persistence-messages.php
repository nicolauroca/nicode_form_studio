<?php
declare(strict_types=1);

(static function () use ($connection, $forms, $registry, $validationEngine, $pipelineActions, $postSubmit, $attemptTokens, $captchaFixture, $storageProviders): void {
    // Fail only the projection registry, after the repository has inserted its
    // response and attempt inside the real transaction. Validation stays real.
    $fieldProvider = new class($registry->get('text'), $connection) implements Nicode\FormStudio\Contract\FieldTypeInterface {
        public bool $fail = true;
        public int $form = 0;
        public bool $observedUncommitted = false;
        public function __construct(private $delegate, private $db) {}
        public function id(): string { return 'text'; }
        public function version(): string { return $this->delegate->version(); }
        public function metadata(): array { return $this->delegate->metadata(); }
        public function validateConfiguration(array $configuration, string $path): array { return $this->delegate->validateConfiguration($configuration, $path); }
        public function normalize(mixed $value, array $configuration): mixed { return $this->delegate->normalize($value, $configuration); }
        public function validate(mixed $value, array $configuration): array { return $this->delegate->validate($value, $configuration); }
        public function serialize(mixed $value): mixed { return $this->delegate->serialize($value); }
        public function multiple(): bool { return false; }
        public function indexType(): ?string {
            if ($this->fail) {
                $attempt = $this->db->row('SELECT id FROM ' . $this->db->table('attempts') . " WHERE form_id=:form AND state='persisting'", [':form' => $this->form]);
                $this->observedUncommitted = $this->db->inTransaction() && $attempt !== null;
                throw new RuntimeException('Private persistence error /internal/database');
            }
            return $this->delegate->indexType();
        }
    };
    $projectionFields = new Nicode\FormStudio\Registry\FieldTypeRegistry(); $projectionFields->register($fieldProvider);
    $submissions = new Nicode\FormStudio\Infrastructure\Database\SubmissionRepository($connection, new Nicode\FormStudio\Search\IndexProjector($projectionFields), random_bytes(32));
    $pipeline = new Nicode\FormStudio\Application\SubmissionPipeline($forms, $submissions, new Nicode\FormStudio\Security\PublicAccess(), $attemptTokens, $captchaFixture, new Nicode\FormStudio\Infrastructure\Database\RateLimiter($connection), $validationEngine, $pipelineActions, $postSubmit, $storageProviders);
    $form = $forms->create('Persistence recovery', 'persistence-message-' . bin2hex(random_bytes(6)), 1); $fieldProvider->form = $form;
    $draft = $forms->draft($form); $field = Nicode\FormStudio\Domain\Uuid::create();
    $draft['elements'] = [['uuid' => $field, 'type' => 'field']];
    $draft['fields'] = [['uuid' => $field, 'name' => 'answer', 'type' => 'text', 'index' => true, 'config' => ['max_length' => 255]]];
    $draft['post_submit']['messages']['persistence_error'] = 'Retry {{form.name}}';
    $draft['translations']['es']['messages']['persistence_error'] = 'Reintenta {{form.name}}';
    $revision = $forms->saveDraft($form, 0, $draft, 1); $version = $forms->publish($form, $revision, 1);
    $counts = static function () use ($connection, $form): array {
        $result = [];
        foreach (['submissions', 'attempts', 'submission_index'] as $table) { $result[$table] = (int) $connection->row('SELECT COUNT(*) AS total FROM ' . $connection->table($table) . ' WHERE form_id=:form', [':form' => $form])['total']; }
        return $result;
    };
    foreach (['en-GB' => 'Retry', 'es-ES' => 'Reintenta'] as $locale => $prefix) {
        $context = new Nicode\FormStudio\Submission\RequestContext(0, [1], $locale, 'persistence-fixture', hash('sha256', $locale), true);
        $token = $attemptTokens->issue($form, $version, 'persistence-fixture:component');
        $request = new Nicode\FormStudio\Submission\SubmitRequest($form, $version, $token, [$field => 'recoverable answer']);
        $before = $counts(); $fieldProvider->fail = true; $fieldProvider->observedUncommitted = false;
        $failed = $pipeline->submit($request, $context);
        if (!$fieldProvider->observedUncommitted || $failed['accepted'] !== false || $failed['category'] !== 'persistence_error' || $failed['message'] !== $prefix . ' Persistence recovery' || str_contains(json_encode($failed), '/internal/database') || $counts() !== $before) { throw new RuntimeException('Persistence failure lost localized error, leaked detail or left partial rows.'); }
        $fieldProvider->fail = false;
        $recovered = $pipeline->submit($request, $context);
        $expected = array_map(static fn (int $count): int => $count + 1, $before);
        if (!($recovered['accepted'] ?? false) || !($recovered['processed'] ?? false) || $counts() !== $expected) { throw new RuntimeException('Same-attempt persistence recovery failed.'); }
        $replay = $pipeline->submit($request, $context);
        if (!($replay['replayed'] ?? false) || $replay['reference'] !== $recovered['reference'] || $counts() !== $expected) { throw new RuntimeException('Recovered persistence replay duplicated data.'); }
    }
    echo "Persistence messages: real transactional projection failure rolls back response/attempt/index, preserves localized safe message and same-attempt recovery/replay.\n";
})();
