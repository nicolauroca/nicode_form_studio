<?php
declare(strict_types=1);

$multiForm = $forms->create('Multivalue search', 'multi-' . bin2hex(random_bytes(5)), 1);
$multiDraft = $forms->draft($multiForm); $multiField = Nicode\FormStudio\Domain\Uuid::create();
$multiDraft['elements'] = [['uuid' => $multiField, 'type' => 'field', 'parent_uuid' => null]];
$multiDraft['fields'] = [['uuid' => $multiField, 'name' => 'choices', 'type' => 'multiselect', 'index' => true, 'config' => [], 'options' => [['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'value' => 'a', 'label' => 'A'], ['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'value' => 'b', 'label' => 'B']]]];
$multiRevision = $forms->saveDraft($multiForm, 0, $multiDraft, 1); $multiVersion = $forms->publish($multiForm, $multiRevision, 1); $multiSpec = $forms->version($multiForm, $multiVersion);
$mixed = $submissions->persist($multiForm, $multiVersion, $multiSpec, [$multiField => ['a', 'b']], hash('sha256', random_bytes(32)));
$onlyB = $submissions->persist($multiForm, $multiVersion, $multiSpec, [$multiField => ['b']], hash('sha256', random_bytes(32)));
$empty = $submissions->persist($multiForm, $multiVersion, $multiSpec, [], hash('sha256', random_bytes(32)));
$found = $search->search(new Nicode\FormStudio\Search\SearchRequest(['form_id' => $multiForm], [['field' => $multiField, 'operator' => 'not_equals', 'value' => 'a']]), new Nicode\FormStudio\Search\SearchScope([$multiForm => false]), $multiSpec);
$foundIds = array_map('intval', array_column($found->rows, 'id')); sort($foundIds); $expectedIds = [$onlyB->id, $empty->id]; sort($expectedIds);
if ($foundIds !== $expectedIds) { throw new RuntimeException('Multivalue negation matched an equal member.'); }
$reindexId = $jobs->enqueue('reindex', ['form_id' => $multiForm], 1);
$connection->execute('DELETE FROM ' . $connection->table('version_field_policy') . ' WHERE form_version_id = :version', [':version' => $multiVersion]);
$handlerRegistry = new Nicode\FormStudio\Registry\JobHandlerRegistry();
$handlerRegistry->register(new Nicode\FormStudio\Jobs\ReindexHandler($connection, $forms, $submissions, $jobs, static fn (int $actor, int $form, string $permission): bool => $actor === 1));
$worker = new Nicode\FormStudio\Jobs\JobWorker($jobs, $handlerRegistry, $connection);
$beforePayload = $submissions->get($multiForm, $mixed->id)['canonical_payload'];
$worker->tick(2);
if ($jobs->get($reindexId)['state'] !== 'pending' || (int) $jobs->get($reindexId)['processed'] !== 2) { throw new RuntimeException('Reindex chunk checkpoint failed.'); }
$worker->tick(2);
if ($jobs->get($reindexId)['state'] !== 'completed' || (int) $jobs->get($reindexId)['processed'] !== 3 || $beforePayload !== $submissions->get($multiForm, $mixed->id)['canonical_payload']) { throw new RuntimeException('Reindex job completion or canonical immutability failed.'); }
if ($connection->row('SELECT id FROM ' . $connection->table('version_field_policy') . ' WHERE form_version_id = :version AND field_uuid = :field', [':version' => $multiVersion, ':field' => $multiField]) === null) { throw new RuntimeException('Reindex did not restore historical access metadata.'); }
echo "Multivalue negation and resumable reindex handler verified.\n";

$historyForm = $forms->create('Historical search privacy', 'history-search-' . bin2hex(random_bytes(5)), 1);
$historyDraft = $forms->draft($historyForm); $historyField = Nicode\FormStudio\Domain\Uuid::create();
$historyDraft['elements'] = [['uuid' => $historyField, 'type' => 'field']];
$historyDraft['fields'] = [['uuid' => $historyField, 'type' => 'text', 'name' => 'private_before', 'index' => true, 'sensitive' => true, 'allow_sensitive_index' => true, 'config' => ['max_length' => 255]]];
$historyRevision = $forms->saveDraft($historyForm, 0, $historyDraft, 1); $privateVersion = $forms->publish($historyForm, $historyRevision, 1); $privateSpec = $forms->version($historyForm, $privateVersion);
$privateResponse = $submissions->persist($historyForm, $privateVersion, $privateSpec, [$historyField => 'old secret'], hash('sha256', random_bytes(32)));
$submissions->persist($historyForm, $privateVersion, $privateSpec, [], hash('sha256', random_bytes(32)));
$historyDraft['fields'][0]['sensitive'] = false;
$historyRevision = $forms->saveDraft($historyForm, (int) $forms->get($historyForm)['draft_revision'], $historyDraft, 1); $publicVersion = $forms->publish($historyForm, $historyRevision, 1); $publicSpec = $forms->version($historyForm, $publicVersion);
$publicResponse = $submissions->persist($historyForm, $publicVersion, $publicSpec, [$historyField => 'public now'], hash('sha256', random_bytes(32)));
$privateQuery = new Nicode\FormStudio\Search\SearchRequest(['form_id' => $historyForm], [['field' => $historyField, 'operator' => 'equals', 'value' => 'old secret']]);
$ordinaryScope = new Nicode\FormStudio\Search\SearchScope([$historyForm => false]); $privilegedScope = new Nicode\FormStudio\Search\SearchScope([$historyForm => true]);
if ($search->search($privateQuery, $ordinaryScope, $publicSpec)->rows !== [] || count($search->search($privateQuery, $privilegedScope, $publicSpec)->rows) !== 1) { throw new RuntimeException('Current public schema disclosed historically sensitive answers.'); }
$negative = $search->search(new Nicode\FormStudio\Search\SearchRequest(['form_id' => $historyForm], [['field' => $historyField, 'operator' => 'not_equals', 'value' => 'old secret']]), $ordinaryScope, $publicSpec);
if (array_map('intval', array_column($negative->rows, 'id')) !== [$publicResponse->id]) { throw new RuntimeException('Negative filter disclosed sensitive or missing historical answers.'); }
$connection->execute('DELETE FROM ' . $connection->table('version_field_policy') . ' WHERE form_version_id = :version', [':version' => $privateVersion]);
if ($search->search($privateQuery, $privilegedScope, $publicSpec)->rows !== []) { throw new RuntimeException('Missing historical policy did not fail closed.'); }
(new Nicode\FormStudio\Infrastructure\Database\VersionFieldPolicy($connection))->rebuild($historyForm, $privateVersion);
if (count($search->search($privateQuery, $privilegedScope, $publicSpec)->rows) !== 1) { throw new RuntimeException('Historical policy rebuild failed.'); }
echo "Historical search privacy: current-public/previous-sensitive fields, negative/missing values and fail-closed policy rebuild verified.\n";
