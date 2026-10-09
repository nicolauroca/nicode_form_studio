<?php
declare(strict_types=1);

define('_JEXEC', 1);
$root = dirname(__DIR__);
require $root . '/build/joomla-6.0.0/libraries/vendor/autoload.php';
require $root . '/src/lib_nicode_form_studio/autoload.php';
$engine = $argv[1] ?? 'mysql';
if (!in_array($engine, ['mysql', 'mysql8', 'postgresql'], true)) { throw new InvalidArgumentException('Usage: database.php [mysql|mysql8|postgresql]'); }
$postgres = $engine === 'postgresql';
$mysql8 = $engine === 'mysql8';
$suffix = $postgres ? '-postgresql' : ($mysql8 ? '-mysql8' : '');
$config = json_decode(ltrim(file_get_contents($root . '/build/database-test' . $suffix . '.json'), "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);
$port = $postgres ? 13368 : ($mysql8 ? 13373 : 13367);
$databaseName = $postgres ? 'formstudio_test_pg' : ($mysql8 ? 'formstudio_test_mysql8' : 'formstudio_test');
if ($config['host'] !== '127.0.0.1' || $config['database'] !== $databaseName || $config['port'] !== $port) { throw new RuntimeException('Refusing non-isolated database.'); }
$pdo = new PDO($postgres ? 'pgsql:host=127.0.0.1;port=13368;dbname=postgres' : 'mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4', $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($postgres) {
    if (!$pdo->query("SELECT 1 FROM pg_database WHERE datname = 'formstudio_test_pg'")->fetchColumn()) { $pdo->exec('CREATE DATABASE formstudio_test_pg ENCODING \'UTF8\''); }
} else { $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . $databaseName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'); }
$driver = (new Joomla\Database\DatabaseFactory())->getDriver($postgres ? 'pgsql' : 'mysql', ['host' => '127.0.0.1', 'port' => $port, 'user' => $config['user'], 'password' => $config['password'], 'database' => $databaseName, 'prefix' => 'nfs_', 'charset' => 'utf8mb4']);
$driver->connect();
if (!$postgres) {
    $driver->setQuery('SELECT @@character_set_client AS client, @@character_set_connection AS connection, @@character_set_results AS results');
    if (array_unique(array_values($driver->loadAssoc())) !== ['utf8mb4']) { throw new RuntimeException('MySQL fixture connection must preserve four-byte Unicode.'); }
}
$sql = file_get_contents($root . '/src/com_nicode_form_studio/administrator/sql/' . ($postgres ? 'postgresql' : 'mysql') . '/install.sql');
foreach (Joomla\Database\DatabaseDriver::splitSql($sql) as $statement) { if (trim($statement) !== '') { $driver->setQuery($statement)->execute(); } }
// Complete the two new indexes in reusable pre-release MySQL fixtures. Native
// same-version installation exercises the equivalent installer postflight.
if (!$postgres) {
    foreach (['action_runs' => 'nfs_action_runs_i2', 'audit_log' => 'nfs_audit_log_i3'] as $historyTable => $historyIndex) {
        $historyTableName = 'nfs_nicode_form_studio_' . $historyTable;
        if (!in_array($historyIndex, array_column($driver->getTableKeys($historyTableName), 'Key_name'), true)) {
            $driver->setQuery('CREATE INDEX ' . $driver->quoteName($historyIndex) . ' ON ' . $driver->quoteName($historyTableName) . ' (created_at, id)')->execute();
        }
    }
}
echo ucfirst($engine) . " schema installed through Joomla DatabaseInterface.\n";
Nicode\FormStudio\Infrastructure\Database\IdentitySchema::upgrade($driver);
Nicode\FormStudio\Infrastructure\Database\InstanceSchema::upgrade($driver);
Nicode\FormStudio\Infrastructure\Database\InstanceSchema::upgrade($driver);
$connection = new Nicode\FormStudio\Infrastructure\Database\Connection($driver);
// Reusable isolated fixtures can retain jobs after an interrupted run. They
// must not compete with this run's deliberately single-job lease assertions.
$connection->execute('UPDATE ' . $connection->table('jobs') . " SET state = 'cancelled', lease_token = NULL, lease_until = NULL, revision = revision + 1 WHERE state IN ('pending', 'retryable', 'running')");
require __DIR__ . '/database-schema-health.php';
require __DIR__ . '/database-instances-schema.php';
if ($connection->row('SELECT id FROM ' . $connection->table('installation_state') . " WHERE state_key = 'schema'") === null) { $connection->insert('installation_state', ['state_key' => 'schema', 'state_json' => '{"version":"1.0.0"}']); }
$registry = new Nicode\FormStudio\Registry\FieldTypeRegistry(); Nicode\FormStudio\Field\CoreFieldTypes::register($registry);
$compiler = new Nicode\FormStudio\Compiler\FormCompiler($registry, new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry());
$forms = new Nicode\FormStudio\Infrastructure\Database\FormRepository($connection, $compiler);
require __DIR__ . '/database-persistence-default.php';
$id = $forms->create("Integration ' form", 'integration-' . bin2hex(random_bytes(5)), 1);
$draft = $forms->draft($id); $uuid = Nicode\FormStudio\Domain\Uuid::create();
$draft['elements'] = [['uuid' => $uuid, 'type' => 'field', 'parent_uuid' => null]];
$draft['fields'] = [['uuid' => $uuid, 'name' => 'answer', 'type' => 'text', 'config' => ['required' => true, 'label' => "O'Reilly <script>"]]];
$revision = $forms->saveDraft($id, 0, $draft, 1);
$version = $forms->publish($id, $revision, 1);
try { $forms->publish($id, $revision, 1); throw new RuntimeException('Stale publish accepted.'); }
catch (Nicode\FormStudio\Domain\ConcurrentEdit) { echo "Optimistic publication concurrency verified.\n"; }
$revision = (int) $forms->get($id)['draft_revision'];
$hash = $forms->version($id, $version)->hash;
$draft['fields'][0]['config']['label'] = 'Changed'; $revision = $forms->saveDraft($id, $revision, $draft, 1);
if ($forms->version($id, $version)->hash !== $hash || (int) $forms->get($id)['published_version_id'] !== $version) { throw new RuntimeException('Draft changed production snapshot.'); }
try { $forms->saveDraft($id, 1, $draft, 1); throw new RuntimeException('Stale edit accepted.'); }
catch (Nicode\FormStudio\Domain\ConcurrentEdit) { echo "Optimistic draft concurrency verified.\n"; }
$second = $forms->publish($id, $revision, 1);
$revision = (int) $forms->get($id)['draft_revision'];
if ($forms->version($id, $second)->hash === $hash) { throw new RuntimeException('Publish failed to create a new snapshot.'); }
$forms->restore($id, $version, $revision, 1);
if ((int) $forms->get($id)['published_version_id'] !== $second || $forms->draft($id)['fields'][0]['config']['label'] !== "O'Reilly <script>") { throw new RuntimeException('Historical restore changed production or lost draft values.'); }
echo "Publish, immutable versions, draft isolation, restore and bound SQL verified.\n";
$draft = $forms->draft($id); $draft['fields'][0]['index'] = true; $draft['fields'][0]['config']['max_length'] = 255;
$revision = $forms->saveDraft($id, (int) $forms->get($id)['draft_revision'], $draft, 1);
$indexedVersion = $forms->publish($id, $revision, 1); $spec = $forms->version($id, $indexedVersion);
$submissions = new Nicode\FormStudio\Infrastructure\Database\SubmissionRepository($connection, new Nicode\FormStudio\Search\IndexProjector($registry), str_repeat('isolated-test-key-', 3));
$attempt = hash('sha256', random_bytes(32));
$submission = $submissions->persist($id, $indexedVersion, $spec, [$uuid => "needle ' OR 1=1 --"], $attempt);
$replay = $submissions->persist($id, $indexedVersion, $spec, [$uuid => "needle ' OR 1=1 --"], $attempt);
if (!$replay->replayed || $replay->id !== $submission->id) { throw new RuntimeException('Idempotency replay failed.'); }
try { $submissions->persist($id, $indexedVersion, $spec, [$uuid => 'different'], $attempt); throw new RuntimeException('Payload replay mismatch accepted.'); }
catch (DomainException) { echo "Idempotency fingerprint mismatch rejected.\n"; }
$before = $submissions->get($id, $submission->id)['canonical_payload']; $submissions->reindex($id, $submission->id, $spec);
if ($before !== $submissions->get($id, $submission->id)['canonical_payload']) { throw new RuntimeException('Reindex changed canonical payload.'); }
for ($i = 0; $i < 4; $i++) { $submissions->persist($id, $indexedVersion, $spec, [$uuid => 'needle ' . $i], hash('sha256', random_bytes(32))); }
$search = new Nicode\FormStudio\Search\SqlSearchProvider($connection, $registry, new Nicode\FormStudio\Search\CursorCodec(random_bytes(32)));
$scope = new Nicode\FormStudio\Search\SearchScope([$id => false]);
$filters = [['field' => $uuid, 'operator' => 'contains', 'value' => 'needle']];
$page = $search->search(new Nicode\FormStudio\Search\SearchRequest(['form_id' => $id], $filters, 2), $scope, $spec);
$next = $search->search(new Nicode\FormStudio\Search\SearchRequest(['form_id' => $id], $filters, 2, $page->nextCursor), $scope, $spec);
if (count($page->rows) !== 2 || count($next->rows) !== 2 || array_intersect(array_column($page->rows, 'id'), array_column($next->rows, 'id')) !== []) { throw new RuntimeException('Cursor pagination failed.'); }
$exact = $search->search(new Nicode\FormStudio\Search\SearchRequest(['form_id' => $id], [['field' => $uuid, 'operator' => 'equals', 'value' => "needle ' OR 1=1 --"]]), $scope, $spec);
if (count($exact->rows) !== 1) { throw new RuntimeException('Bound field search failed.'); }
if ($search->search(new Nicode\FormStudio\Search\SearchRequest(), new Nicode\FormStudio\Search\SearchScope([]))->rows !== []) { throw new RuntimeException('Empty ACL scope leaked rows.'); }
echo "Canonical persistence, typed projection, replay, reindex, bound search and keyset pagination verified.\n";
$testNow = time();
$jobs = new Nicode\FormStudio\Infrastructure\Database\JobRepository($connection, static function () use (&$testNow): int { return $testNow; });
$jobId = $jobs->enqueue('test-chunks', ['form_id' => $id], 1);
$lease = $jobs->claim(10); if ($lease === null || $lease->id !== $jobId || $jobs->claim() !== null) { throw new RuntimeException('Job claim isolation failed.'); }
$testNow += 11; $replacement = $jobs->claim(60);
$jobs->renew($replacement, 60);
if ($replacement === null || $replacement->token === $lease->token) { throw new RuntimeException('Stalled job recovery failed.'); }
try { $jobs->checkpoint($lease, new Nicode\FormStudio\Jobs\JobProgress([], 99)); throw new RuntimeException('Expired worker checkpoint accepted.'); }
catch (Nicode\FormStudio\Jobs\LeaseLost) { echo "Expired job worker rejected.\n"; }
$jobs->checkpoint($replacement, new Nicode\FormStudio\Jobs\JobProgress(['last_id' => 20], 20));
$resumed = $jobs->claim(); if ($resumed->cursor !== ['last_id' => 20] || $resumed->processed !== 20) { throw new RuntimeException('Job progress resume failed.'); }
$jobs->cancel($jobId);
try { $jobs->checkpoint($resumed, new Nicode\FormStudio\Jobs\JobProgress([], 1, complete: true)); throw new RuntimeException('Cancelled checkpoint accepted.'); }
catch (Nicode\FormStudio\Jobs\LeaseLost) { echo "Cancelled job worker rejected.\n"; }
echo "Job leases, recovery, durable cursor/counts and cancellation verified.\n";
require __DIR__ . '/database-actions.php';
$limitClock = 1800000000;
$limiter = new Nicode\FormStudio\Infrastructure\Database\RateLimiter($connection, static function () use (&$limitClock): int { return $limitClock; });
$limitScope = hash('sha256', random_bytes(32));
if (!$limiter->consume($limitScope, 2, 60)->allowed || !$limiter->consume($limitScope, 2, 60)->allowed || $limiter->consume($limitScope, 2, 60)->allowed || $limiter->consume($limitScope, 2, 60)->retryAfter !== 60) { throw new RuntimeException('Rate limit boundary failed.'); }
$limitClock += 60;
if (!$limiter->consume($limitScope, 2, 60)->allowed) { throw new RuntimeException('Rate limit window did not reset.'); }
echo "Atomic rate limit bounds and window expiration verified.\n";
require __DIR__ . '/database-search.php';
require __DIR__ . '/database-search-selection.php';
require __DIR__ . '/database-search-order.php';
require __DIR__ . '/database-privacy.php';
require __DIR__ . '/database-file-cleanup-recovery.php';
require __DIR__ . '/database-request-metadata.php';
require __DIR__ . '/database-read.php';
require __DIR__ . '/database-export.php';
require __DIR__ . '/database-pipeline.php';
require __DIR__ . '/database-lifecycle-events.php';
require __DIR__ . '/database-form-administration.php';
require __DIR__ . '/database-publication-readiness.php';
require __DIR__ . '/database-form-duplication.php';
require __DIR__ . '/database-form-exchange.php';
require __DIR__ . '/database-templates.php';
require __DIR__ . '/database-source-resources.php';
require __DIR__ . '/database-display.php';
require __DIR__ . '/database-translations.php';
require __DIR__ . '/database-submission-administration.php';
require __DIR__ . '/database-response-history.php';
require __DIR__ . '/database-bulk.php';
require __DIR__ . '/database-job-administration.php';
require __DIR__ . '/database-action-retries.php';
require __DIR__ . '/database-mail-retries.php';
require __DIR__ . '/database-mail-attachments.php';
require __DIR__ . '/database-retention-dispatch.php';
require __DIR__ . '/database-technical-log.php';
require __DIR__ . '/database-audit-log.php';
require __DIR__ . '/database-option-sets.php';
require __DIR__ . '/database-form-options.php';
require __DIR__ . '/database-form-deletion.php';
require __DIR__ . '/database-dashboard.php';
require __DIR__ . '/database-upload-journal.php';
require __DIR__ . '/database-version-history.php';
require __DIR__ . '/database-rate-cleanup.php';
require __DIR__ . '/database-attempt-cleanup.php';
require __DIR__ . '/database-attempt-race.php';
require __DIR__ . '/database-rate-concurrency.php';
require __DIR__ . '/database-transaction-integrity.php';
require __DIR__ . '/database-history-retention.php';
require __DIR__ . '/database-temporal.php';
require __DIR__ . '/database-numeric.php';
require __DIR__ . '/database-field-identity.php';
require __DIR__ . '/database-field-policy.php';
require __DIR__ . '/database-selection-identity.php';
require __DIR__ . '/database-text.php';
require __DIR__ . '/database-repeated-persistence.php';
require __DIR__ . '/database-repeated-read.php';
require __DIR__ . '/database-repeated-export.php';
require __DIR__ . '/database-json-lifecycle.php';
require __DIR__ . '/database-repeated-search.php';
require __DIR__ . '/database-repeated-actions.php';
require __DIR__ . '/database-repeated-pipeline.php';
require __DIR__ . '/database-repeated-public.php';
$suiteFiles = array_values(array_map('basename', array_filter(get_included_files(), static fn (string $file): bool => str_starts_with(basename($file), 'database-') && str_ends_with($file, '.php'))));
file_put_contents($root . '/build/database-test' . $suffix . '-results.json', json_encode(['passed' => true, 'database' => $driver->getVersion(), 'engine' => $engine, 'joomla' => '6.0.0', 'timestamp' => gmdate(DATE_ATOM), 'suite_files' => $suiteFiles, 'scenarios' => ['schema', 'bound queries', 'publication', 'draft isolation', 'optimistic edit/publish conflict', 'restore', 'canonical persistence', 'typed projection', 'replay protection', 'reindex immutability', 'cursor pagination', 'ACL search scope', 'job claims', 'lease recovery', 'renewal', 'cancel']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
