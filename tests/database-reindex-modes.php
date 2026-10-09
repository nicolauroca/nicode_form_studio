<?php
declare(strict_types=1);

define('_JEXEC', 1); $root = dirname(__DIR__);
require $root . '/build/joomla-6.0.0/libraries/vendor/autoload.php';
require $root . '/src/lib_nicode_form_studio/autoload.php';
$engine = $argv[1] ?? 'mysql';
[$suffix, $port, $database, $adapter] = match ($engine) {
    'mysql' => ['', 13367, 'formstudio_test', 'mysql'], 'mysql8' => ['-mysql8', 13373, 'formstudio_test_mysql8', 'mysql'], 'postgresql' => ['-postgresql', 13368, 'formstudio_test_pg', 'pgsql'], default => throw new InvalidArgumentException('Unknown isolated database engine.')
};
$config = json_decode(ltrim(file_get_contents($root . '/build/database-test' . $suffix . '.json'), "\xEF\xBB\xBF"), true, flags: JSON_THROW_ON_ERROR);
if ($config['host'] !== '127.0.0.1' || $config['port'] !== $port || $config['database'] !== $database) { throw new RuntimeException('Refusing non-isolated reindex fixture.'); }
$driver = (new Joomla\Database\DatabaseFactory())->getDriver($adapter, ['host' => '127.0.0.1', 'port' => $port, 'user' => $config['user'], 'password' => $config['password'], 'database' => $database, 'prefix' => 'nfs_', 'charset' => 'utf8mb4']);
$db = new Nicode\FormStudio\Infrastructure\Database\Connection($driver);
$fields = new Nicode\FormStudio\Registry\FieldTypeRegistry(); Nicode\FormStudio\Field\CoreFieldTypes::register($fields);
$compiler = new Nicode\FormStudio\Compiler\FormCompiler($fields, new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry());
$forms = new Nicode\FormStudio\Infrastructure\Database\FormRepository($db, $compiler);
$responses = new Nicode\FormStudio\Infrastructure\Database\SubmissionRepository($db, new Nicode\FormStudio\Search\IndexProjector($fields), str_repeat('reindex-fixture-', 3));
$jobs = new Nicode\FormStudio\Infrastructure\Database\JobRepository($db);
$allowed = true; $authorize = static function (int $actor, ?int $form, string $permission) use (&$allowed): bool { return $actor === 1 && $allowed; };
$handler = new Nicode\FormStudio\Jobs\ReindexHandler($db, $forms, $responses, $jobs, $authorize);
$handlers = new Nicode\FormStudio\Registry\JobHandlerRegistry(); $handlers->register($handler);
$search = new Nicode\FormStudio\Search\SqlSearchProvider($db, $fields, new Nicode\FormStudio\Search\CursorCodec(str_repeat('reindex-cursor-', 3)));
$admin = new Nicode\FormStudio\Application\JobAdministration($db, $forms, $jobs, $handlers, $search, $authorize);
$assert = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$fixtureForms = []; $rows = []; $payloads = [];
try {
    foreach ([0, 1] as $number) {
        $form = $forms->create('Reindex modes', 'reindex-modes-' . bin2hex(random_bytes(6)), 1); $fixtureForms[] = $form;
        $draft = $forms->draft($form); $field = Nicode\FormStudio\Domain\Uuid::create();
        $draft['elements'] = [['uuid' => $field, 'type' => 'field']];
        $draft['fields'] = [['uuid' => $field, 'type' => 'text', 'name' => 'answer', 'index' => true, 'config' => ['max_length' => 255]]];
        $version = $forms->publish($form, $forms->saveDraft($form, 0, $draft, 1), 1); $spec = $forms->version($form, $version);
        foreach ([1, 2] as $day) {
            $response = $responses->persist($form, $version, $spec, [$field => 'row-' . $day], hash('sha256', random_bytes(32)));
            $rows[$form][] = $response->id; $payloads[$response->id] = $responses->get($form, $response->id)['canonical_payload'];
            $db->execute('UPDATE ' . $db->table('submissions') . ' SET received_at = :date WHERE id = :id', [':date' => '2026-01-0' . $day . ' 12:00:00', ':id' => $response->id]);
        }
    }
    $reset = static function () use ($db, $fixtureForms): void {
        foreach ($fixtureForms as $form) {
            $db->execute('DELETE FROM ' . $db->table('submission_index') . ' WHERE form_id = :form', [':form' => $form]);
            $db->execute('UPDATE ' . $db->table('submissions') . ' SET index_pending = 1 WHERE form_id = :form', [':form' => $form]);
        }
    };
    // Give only our newly enqueued job a synthetic lease, without claiming or cancelling other fixtures' jobs.
    $run = static function (int $id, array $initial = [], ?Closure $afterChunk = null) use ($db, $jobs, $handler): void {
        $cursor = $initial;
        for ($chunk = 0; $chunk < 20; $chunk++) {
            $row = $jobs->get($id); $token = bin2hex(random_bytes(32)); $revision = (int) $row['revision'];
            $db->execute('UPDATE ' . $db->table('jobs') . " SET state = 'running', lease_token = :token, lease_until = :until WHERE id = :id", [':token' => $token, ':until' => gmdate('Y-m-d H:i:s', time() + 60), ':id' => $id]);
            $lease = new Nicode\FormStudio\Jobs\JobLease($id, $row['uuid'], 'reindex', 1, json_decode($row['parameters'], true, flags: JSON_THROW_ON_ERROR), $cursor, $token, $revision, (int) $row['processed'], 0);
            $progress = $handler->run($lease, 1); $jobs->checkpoint($lease, $progress); $cursor = $progress->cursor;
            if ($afterChunk !== null) { $afterChunk($progress); }
            if ($progress->complete) { return; }
        }
        throw new RuntimeException('Reindex cursor did not terminate.');
    };
    $indexed = static fn (int $form): array => array_map('intval', array_column($db->rows('SELECT submission_id FROM ' . $db->table('submission_index') . ' WHERE form_id = :form ORDER BY submission_id', [':form' => $form]), 'submission_id'));
    $first = $fixtureForms[0]; $second = $fixtureForms[1];
    foreach ([['submission_id' => $rows[$first][0]], ['received_from' => '2026-01-02 00:00:00', 'received_to' => '2026-01-02 23:59:59'], []] as $selection) {
        $reset(); $job = $admin->enqueue(1, $first, 'reindex', $selection); $run($job);
        $expected = isset($selection['submission_id']) ? [$rows[$first][0]] : (isset($selection['received_from']) ? [$rows[$first][1]] : $rows[$first]);
        $assert($indexed($first) === $expected && $indexed($second) === [], 'Selected reindex crossed its response/period/form boundary.');
    }
    $reset(); $job = $admin->enqueue(1, 0, 'reindex', ['all_forms' => true]);
    $run($job, ['last_form' => $first - 1, 'high_form' => $second]);
    $assert($indexed($first) === $rows[$first] && $indexed($second) === $rows[$second] && (int) $jobs->get($job)['processed'] === 4, 'Full reconstruction lost a form or response.');
    foreach ($rows as $form => $ids) { foreach ($ids as $id) { $assert($responses->get($form, $id)['canonical_payload'] === $payloads[$id], 'Reindex modified canonical payload.'); } }
    foreach ([['submission_id' => []], ['submission_id' => -1], ['received_from' => '2026-02-30 00:00:00'], ['received_from' => '2026-02-01 00:00:00', 'received_to' => '2026-01-01 00:00:00']] as $selection) {
        try { $admin->enqueue(1, $first, 'reindex', $selection); throw new RuntimeException('Invalid selection accepted.'); } catch (InvalidArgumentException) {}
    }
    try { $admin->enqueue(1, $first, 'reindex', ['submission_id' => $rows[$second][0]]); throw new RuntimeException('Foreign response selected.'); } catch (OutOfBoundsException) {}
    require __DIR__ . '/database-index-backfill.php';
    $allowed = false;
    try { $admin->enqueue(1, 0, 'reindex', ['all_forms' => true]); throw new RuntimeException('Denied full reconstruction enqueued.'); } catch (DomainException) {}
    file_put_contents($root . '/build/reindex-modes' . $suffix . '-results.json', json_encode(['passed' => true, 'database' => $driver->getVersion(), 'forms' => $fixtureForms, 'checks' => ['submission', 'UTC period', 'form', 'all forms with bounded fixture window', 'one-row resumable batches', 'canonical immutability', 'invalid selection and permission rejection'], 'timestamp' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "Reindex modes passed: response, UTC period, form, bounded all-forms reconstruction, resumable one-row batches, immutable payloads and rejected invalid/foreign/denied requests.\n";
} finally { foreach ($fixtureForms as $form) { $forms->deactivate($form, (int) $forms->get($form)['draft_revision'], 1, 'unpublished'); } }
