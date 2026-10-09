<?php
declare(strict_types=1);
$bulkForm = $forms->create('Bulk atomic fixture', 'bulk-' . bin2hex(random_bytes(5)), 1); $bulkDraft = $forms->draft($bulkForm); $bulkField = Nicode\FormStudio\Domain\Uuid::create();
$bulkDraft['elements'] = [['uuid' => $bulkField, 'type' => 'field']]; $bulkDraft['fields'] = [['uuid' => $bulkField, 'type' => 'text', 'name' => 'answer', 'index' => true, 'config' => ['max_length' => 255]]];
$bulkRevision = $forms->saveDraft($bulkForm, 0, $bulkDraft, 1); $bulkVersion = $forms->publish($bulkForm, $bulkRevision, 1); $bulkSpec = $forms->version($bulkForm, $bulkVersion);
$bulkResponses = [];
foreach (['match', 'match', 'match', 'other'] as $value) { $bulkResponses[] = $submissions->persist($bulkForm, $bulkVersion, $bulkSpec, [$bulkField => $value], hash('sha256', random_bytes(32))); }
$bulkPermission = static fn (int $actor, ?int $form, string $permission): bool => $actor === 1;
$bulkHandler = new Nicode\FormStudio\Jobs\BulkSubmissionHandler($connection, $forms, $jobs, $search, new Nicode\FormStudio\Application\SubmissionAdministration($connection, $bulkPermission), $maintenance, $bulkPermission);
$handlerRegistry->register($bulkHandler); $atomicWorker = new Nicode\FormStudio\Jobs\JobWorker($jobs, $handlerRegistry, $connection);
$bulkParameters = ['form_id' => $bulkForm, 'version_id' => $bulkVersion, 'operation' => 'state', 'state' => 'reviewed', 'query' => ['sort' => 'received_at_asc', 'filters' => ['form_id' => $bulkForm, 'state' => 'new'], 'fields' => [['field' => $bulkField, 'operator' => 'equals', 'value' => 'match']]]];
$bulkId = $jobs->enqueue('submission-bulk', $bulkParameters, 1); $atomicWorker->tick(2);
$submissions->persist($bulkForm, $bulkVersion, $bulkSpec, [$bulkField => 'match'], hash('sha256', random_bytes(32))); $atomicWorker->tick(2);
if ($jobs->get($bulkId)['state'] !== 'completed' || (int) $jobs->get($bulkId)['processed'] !== 3 || $submissions->get($bulkForm, $bulkResponses[3]->id)['state'] !== 'new') { throw new RuntimeException('Bulk cursor/state filtering failed.'); }
foreach (array_slice($bulkResponses, 0, 3) as $response) { if ($submissions->get($bulkForm, $response->id)['state'] !== 'reviewed') { throw new RuntimeException('Bulk update lost a matching row.'); } }
$rollbackHandler = new class($connection, $bulkResponses[3]->id, $testNow) implements Nicode\FormStudio\Contract\TransactionalJobHandlerInterface {
    private int $clock;
    public function __construct(private $db, private int $response, int &$clock) { $this->clock =& $clock; }
    public function id(): string { return 'test-atomic-rollback'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return []; }
    public function validateConfiguration(array $configuration, string $path): array { return []; }
    public function run(Nicode\FormStudio\Jobs\JobLease $job, int $limit): Nicode\FormStudio\Jobs\JobProgress {
        $this->db->execute('UPDATE ' . $this->db->table('submissions') . " SET state = 'spam' WHERE id = :id", [':id' => $this->response]); $this->clock += 61;
        return new Nicode\FormStudio\Jobs\JobProgress([], 1, complete: true);
    }
};
$handlerRegistry->register($rollbackHandler); $rollbackJob = $jobs->enqueue($rollbackHandler->id(), [], 1);
try { $atomicWorker->tick(2); throw new RuntimeException('Expired atomic checkpoint succeeded.'); } catch (Nicode\FormStudio\Jobs\LeaseLost) {}
if ($submissions->get($bulkForm, $bulkResponses[3]->id)['state'] !== 'new' || (int) $jobs->get($rollbackJob)['processed'] !== 0) { throw new RuntimeException('Atomic checkpoint failure left business mutations committed.'); }
$jobs->cancel($rollbackJob);
$privacyParameters = ['form_id' => $bulkForm, 'version_id' => $bulkVersion, 'operation' => 'anonymize', 'query' => ['filters' => ['form_id' => $bulkForm, 'state' => 'reviewed'], 'fields' => []]];
$bulkAnonymize = $jobs->enqueue('submission-bulk', $privacyParameters, 1); $atomicWorker->tick(2); $atomicWorker->tick(2);
if ($jobs->get($bulkAnonymize)['state'] !== 'completed' || (int) $jobs->get($bulkAnonymize)['processed'] !== 3) { throw new RuntimeException('Filtered bulk anonymization failed.'); }
foreach (array_slice($bulkResponses, 0, 3) as $response) { if ($submissions->get($bulkForm, $response->id)['anonymized_at'] === null) { throw new RuntimeException('Bulk privacy skipped a match.'); } }
$privacyParameters['operation'] = 'delete'; $bulkDelete = $jobs->enqueue('submission-bulk', $privacyParameters, 1); $atomicWorker->tick(2); $atomicWorker->tick(2);
if ($jobs->get($bulkDelete)['state'] !== 'completed' || (int) $jobs->get($bulkDelete)['processed'] !== 3 || $submissions->get($bulkForm, $bulkResponses[3]->id)['state'] !== 'new') { throw new RuntimeException('Filtered bulk delete affected non-matching responses.'); }
$unauthorizedBulk = $jobs->enqueue('submission-bulk', $bulkParameters, 2);
try { $atomicWorker->tick(2); throw new RuntimeException('Unauthorized bulk operation executed.'); } catch (DomainException) {}
if ($jobs->get($unauthorizedBulk)['state'] !== 'failed') { throw new RuntimeException('Unauthorized bulk job did not fail.'); }
echo "Bulk jobs: typed criteria, stable cursor, late-response exclusion and atomic mutation/checkpoint rollback verified.\n";
require __DIR__ . '/database-bulk-states.php';
