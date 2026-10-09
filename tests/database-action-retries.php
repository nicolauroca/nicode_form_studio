<?php
declare(strict_types=1);
$retryForm = $actionForms->create('Retry fixture', 'retry-' . bin2hex(random_bytes(5)), 1);
$retryDraft = $actionForms->draft($retryForm); $retryDraft['elements'] = $actionDraft['elements']; $retryDraft['fields'] = $actionDraft['fields']; $retryDraft['actions'] = $actionDraft['actions'];
$retryRevision = $actionForms->saveDraft($retryForm, 0, $retryDraft, 1); $retryVersion = $actionForms->publish($retryForm, $retryRevision, 1); $retrySpec = $actionForms->version($retryForm, $retryVersion);
$retryResponse = $submissions->persist($retryForm, $retryVersion, $retrySpec, [], hash('sha256', random_bytes(32)));
$retryContext = new Nicode\FormStudio\Actions\ActionContext($retrySpec, [], $retryResponse->uuid, gmdate('Y-m-d H:i:s'));
$actionProvider->recover = false; $actionProvider->calls = [];
$engine->execute($retryResponse->id, $retryContext);
$retries = new Nicode\FormStudio\Application\ActionRetries($connection, $actionForms, $submissions, $runs, $jobs, $actionRegistry, $bulkPermission);
$attempts = $retries->eligible(1, $retryForm, $retryResponse->id);
if ($attempts !== [$actionIds['failed'] => 1] || $retries->eligible(2, $retryForm, $retryResponse->id) !== []) { throw new RuntimeException('Retry eligibility or permissions failed.'); }
$retryHandler = new Nicode\FormStudio\Jobs\ActionRetryHandler($actionForms, $submissions, $jobs, $engine, $bulkPermission); $handlerRegistry->register($retryHandler);
$retryJob = $retries->enqueue(1, $retryForm, $retryResponse->id, $attempts);
$retryLease = $jobs->claim();
if ($retryLease->id !== $retryJob) { throw new RuntimeException('Unexpected pending retry job.'); }
$retryHandler->run($retryLease, 1); // Simulate losing the checkpoint after another definite failure.
$testNow += 61; $atomicWorker->tick(1);
if ($jobs->get($retryJob)['state'] !== 'completed' || $actionProvider->calls !== ['success' => 1, 'failed' => 2]) { throw new RuntimeException('Job replay repeated a failed action beyond its expected attempt.'); }
try { $retries->enqueue(1, $retryForm, $retryResponse->id, $attempts); throw new RuntimeException('Stale action attempt accepted.'); } catch (Nicode\FormStudio\Domain\ConcurrentEdit) {}
$actionProvider->recover = true;
$secondRetry = $retries->enqueue(1, $retryForm, $retryResponse->id, $retries->eligible(1, $retryForm, $retryResponse->id)); $atomicWorker->tick(1);
if ($jobs->get($secondRetry)['state'] !== 'completed' || $actionProvider->calls !== ['success' => 1, 'failed' => 3, 'unknown' => 1, 'after' => 1] || $retries->eligible(1, $retryForm, $retryResponse->id) !== []) { throw new RuntimeException('Retry continuation repeated completed/uncertain effects.'); }
$runs->assertContext($retryResponse->id, $retryContext);
$retryAudit = $connection->rows('SELECT actor_id, safe_metadata FROM ' . $connection->table('audit_log') . " WHERE submission_uuid = :uuid AND event_type = 'action.retry_requested' ORDER BY id", [':uuid' => $retryResponse->uuid]);
if (count($retryAudit) !== 2 || array_map(static fn (array $row): int => (int) json_decode($row['safe_metadata'], true, 32, JSON_THROW_ON_ERROR)['job_id'], $retryAudit) !== [$retryJob, $secondRetry] || array_unique(array_map('intval', array_column($retryAudit, 'actor_id'))) !== [1]) { throw new RuntimeException('Retry audit included stale requests or lost job identities.'); }
$maintenance->apply($retryForm, $retryResponse->id, 1, 'anonymize');
try { $runs->claim($retryResponse->id, $actionIds['failed'], 'test-action', true); throw new RuntimeException('Action claim crossed anonymization boundary.'); } catch (DomainException) {}
echo "Action retry jobs: authorized eligibility, expected-attempt fencing after lost checkpoint, blocking continuation and anonymization race verified.\n";
$interruptedResponse = $submissions->persist($retryForm, $retryVersion, $retrySpec, [], hash('sha256', random_bytes(32)));
$interruptedLease = $runs->claim($interruptedResponse->id, $actionIds['success'], 'test-action');
try { $maintenance->apply($retryForm, $interruptedResponse->id, 1, 'anonymize'); throw new RuntimeException('Privacy erased an actively leased action.'); } catch (DomainException) {}
$connection->execute('UPDATE ' . $connection->table('action_runs') . ' SET lease_until = :past WHERE id = :id', [':past' => '2000-01-01 00:00:00', ':id' => $interruptedLease->id]);
if (!$maintenance->apply($retryForm, $interruptedResponse->id, 1, 'anonymize')) { throw new RuntimeException('Interrupted action blocked privacy indefinitely.'); }
try { $runs->finish($interruptedLease, 'succeeded', 'late_completion'); throw new RuntimeException('Stale action resurrected a removed run.'); } catch (DomainException) {}
$runs->summarize($interruptedResponse->id, 'succeeded');
if ($submissions->get($retryForm, $interruptedResponse->id)['action_status'] !== 'anonymized') { throw new RuntimeException('Late action summary overwrote privacy state.'); }
