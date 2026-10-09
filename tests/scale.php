<?php
declare(strict_types=1);

// Synthetic scale fixture only: 100 forms, 1M responses, 3M index rows, 1M runs.
// Never points at a Joomla installation or a user database.
$root = dirname(__DIR__); define('_JEXEC', 1);
require $root . '/build/joomla-6.0.0/libraries/vendor/autoload.php'; require $root . '/src/lib_nicode_form_studio/autoload.php';
$config = json_decode(ltrim(file_get_contents($root . '/build/database-test.json'), "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);
if ($config['host'] !== '127.0.0.1' || $config['port'] !== 13367) { throw new RuntimeException('Refusing non-isolated scale database.'); }
$pdo = new PDO('mysql:host=127.0.0.1;port=13367;charset=utf8mb4', $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS formstudio_scale CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'); $pdo->exec('USE formstudio_scale');
$driver = (new Joomla\Database\DatabaseFactory())->getDriver('mysql', ['host' => '127.0.0.1', 'port' => 13367, 'user' => $config['user'], 'password' => $config['password'], 'database' => 'formstudio_scale', 'prefix' => 'scale_', 'charset' => 'utf8mb4']);
$driver->connect();
echo "Inspecting and migrating exact identity columns in the isolated scale fixture.\n"; flush();
$identityStarted = hrtime(true);
// Existing fixtures predate ADR 0018; perform the same resumable migration as
// the native installer before recording plans against the current schema.
if (in_array('scale_nicode_form_studio_submission_index', $driver->getTableList(), true)) { Nicode\FormStudio\Infrastructure\Database\IdentitySchema::upgrade($driver); }
if (in_array('scale_nicode_form_studio_submission_index', $driver->getTableList(), true)) { Nicode\FormStudio\Infrastructure\Database\InstanceSchema::upgrade($driver); }
$identityMigrationMs = (hrtime(true) - $identityStarted) / 1e6;
echo 'Identity schema ready after ' . round($identityMigrationMs, 2) . " ms.\n"; flush();
file_put_contents($root . '/build/scale-identity-migration.json', json_encode(['timestamp' => gmdate(DATE_ATOM), 'duration_ms' => $identityMigrationMs, 'schema' => 'ADR 0018', 'database' => 'formstudio_scale'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
foreach (Joomla\Database\DatabaseDriver::splitSql(file_get_contents($root . '/src/com_nicode_form_studio/administrator/sql/mysql/install.sql')) as $sql) { if (trim($sql) !== '') { $driver->setQuery($sql)->execute(); } }
$db = new Nicode\FormStudio\Infrastructure\Database\Connection($driver);
$fields = new Nicode\FormStudio\Registry\FieldTypeRegistry(); Nicode\FormStudio\Field\CoreFieldTypes::register($fields);
$actions = new Nicode\FormStudio\Registry\ActionRegistry();
$actions->register(new Nicode\FormStudio\Actions\EmailAction(new class implements Nicode\FormStudio\Contract\MailTransportInterface { public function send(Nicode\FormStudio\Actions\MailMessage $message): void { throw new LogicException('Scale fixture must never deliver mail.'); } }, new Nicode\FormStudio\Actions\TokenTemplate()));
$compiler = new Nicode\FormStudio\Compiler\FormCompiler($fields, $actions, new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry());
$forms = new Nicode\FormStudio\Infrastructure\Database\FormRepository($db, $compiler);
$fieldIds = ['email' => '0a4fbe36-0885-4269-82a0-4e5c15696d01', 'amount' => '0a4fbe36-0885-4269-82a0-4e5c15696d02', 'country' => '0a4fbe36-0885-4269-82a0-4e5c15696d03'];
$formIds = []; $firstSpec = null; $seedStarted = microtime(true);
for ($formNumber = 1; $formNumber <= 100; $formNumber++) {
    $alias = 'scale-fixture-' . $formNumber;
    $existing = $db->row('SELECT id, published_version_id FROM ' . $db->table('forms') . ' WHERE alias = :alias', [':alias' => $alias]);
    if ($existing === null) {
        $id = $forms->create('Scale form ' . $formNumber, $alias, 1); $draft = $forms->draft($id);
        foreach ($fieldIds as $name => $uuid) {
            $draft['elements'][] = ['uuid' => $uuid, 'type' => 'field', 'parent_uuid' => null];
            $draft['fields'][] = ['uuid' => $uuid, 'name' => $name, 'type' => $name === 'amount' ? 'decimal' : ($name === 'email' ? 'email' : 'text'), 'index' => true, 'config' => $name === 'amount' ? ['scale' => 2] : ['max_length' => 255]];
        }
        $draft['actions'][] = ['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'type' => 'email_notification', 'config' => ['to' => ['fixture@example.test'], 'subject' => 'Synthetic fixture', 'body_text' => 'No mail is sent.']];
        $revision = $forms->saveDraft($id, 0, $draft, 1); $version = $forms->publish($id, $revision, 1);
    } else { $id = (int) $existing['id']; $version = (int) $existing['published_version_id']; }
    $formIds[] = $id; $spec = $forms->version($id, $version); $firstSpec ??= $spec;
    $actionUuid = $spec->toArray()['actions'][0]['uuid'];
    $target = $formNumber === 1 ? 500000 : ($formNumber === 100 ? 5100 : 5050);
    $done = (int) $pdo->query('SELECT COUNT(*) FROM scale_nicode_form_studio_submissions WHERE form_id = ' . $id)->fetchColumn();
    if ($done < $target && !in_array('--seed', $argv, true)) { throw new RuntimeException('Scale fixture incomplete. Run php tests/scale.php --seed explicitly.'); }
    while ($done < $target) {
        $count = min(10000, $target - $done); $sequence = '(seq + ' . $done . ')';
        $email = "CONCAT('person', $sequence, '@example.test')"; $amount = "CAST(CAST(($sequence % 100000) / 100 AS DECIMAL(12,2)) AS CHAR)"; $country = "ELT(($sequence % 5) + 1, 'ES','PT','FR','DE','IT')";
        $payload = "JSON_OBJECT('schema_version','1.0','values',JSON_OBJECT('{$fieldIds['email']}',$email,'{$fieldIds['amount']}',$amount,'{$fieldIds['country']}',$country),'consents',JSON_OBJECT(),'option_labels',JSON_OBJECT())";
        $pdo->beginTransaction();
        try {
            $last = (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM scale_nicode_form_studio_submissions')->fetchColumn();
            $pdo->exec("INSERT INTO scale_nicode_form_studio_submissions (uuid,form_id,form_version_id,state,received_at,processed_at,user_id,channel,locale,canonical_payload,payload_schema_version,action_status,expires_at,anonymized_at,attempt_hash,index_pending) SELECT UUID(),$id,$version,IF($sequence % 8 = 0,'reviewed','new'),DATE_ADD('2021-01-01',INTERVAL ($sequence * 180 + $formNumber * 1000) SECOND),NULL,NULL,'component','en-GB',$payload,'1.0','succeeded',NULL,NULL,SHA2(CONCAT('scale:',$id,':',$sequence),256),0 FROM seq_1_to_$count");
            foreach ($fieldIds as $name => $uuid) {
                $type = $name === 'amount' ? 'decimal' : 'keyword'; $column = 'value_' . $type;
                $pdo->exec("INSERT INTO scale_nicode_form_studio_submission_index (submission_id,form_id,form_version_id,field_uuid,value_type,$column,ordinal) SELECT id,form_id,form_version_id,'$uuid','$type',JSON_UNQUOTE(JSON_EXTRACT(canonical_payload,'$.values.\"$uuid\"')),0 FROM scale_nicode_form_studio_submissions WHERE id > $last AND form_id = $id");
            }
            $pdo->exec("INSERT INTO scale_nicode_form_studio_action_runs (submission_id,action_uuid,action_type,attempt,state,created_at,started_at,finished_at,result_code,next_retry_at,lease_token,lease_until,revision) SELECT id,'$actionUuid','email_notification',1,'succeeded',received_at,received_at,received_at,'synthetic_fixture',NULL,NULL,NULL,1 FROM scale_nicode_form_studio_submissions WHERE id > $last AND form_id = $id");
            $pdo->commit();
        } catch (Throwable $error) { $pdo->rollBack(); throw $error; }
        $done += $count;
        if ($formNumber === 1 && $done % 100000 === 0) { echo "Large form: $done / $target responses seeded.\n"; flush(); }
    }
    if ($formNumber % 10 === 0) { echo "$formNumber / 100 forms seeded.\n"; flush(); }
}
$counts = [];
foreach (['forms', 'submissions', 'submission_index', 'action_runs'] as $table) { $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM scale_nicode_form_studio_' . $table)->fetchColumn(); }
if ($counts !== ['forms' => 100, 'submissions' => 1000000, 'submission_index' => 3000000, 'action_runs' => 1000000]) { throw new RuntimeException('Scale fixture cardinality mismatch: ' . json_encode($counts)); }
$pdo->query('ANALYZE TABLE scale_nicode_form_studio_submissions, scale_nicode_form_studio_submission_index')->fetchAll();
$search = new Nicode\FormStudio\Search\SqlSearchProvider($db, $fields, new Nicode\FormStudio\Search\CursorCodec(str_repeat('benchmark-key-', 3)));
$scope = new Nicode\FormStudio\Search\SearchScope(array_fill_keys($formIds, false));
$cases = [
    'global_latest' => [[], [], null],
    'large_form_latest' => [['form_id' => $formIds[0]], [], null],
    'rare_email' => [['form_id' => $formIds[0]], [['field' => $fieldIds['email'], 'operator' => 'equals', 'value' => 'person123456@example.test']], $firstSpec],
    'numeric_and_country' => [['form_id' => $formIds[0]], [['field' => $fieldIds['amount'], 'operator' => 'greater', 'value' => '950'], ['field' => $fieldIds['country'], 'operator' => 'equals', 'value' => 'ES']], $firstSpec],
];
$results = [];
foreach ($cases as $name => [$filters, $fieldFilters, $selected]) {
    $request = new Nicode\FormStudio\Search\SearchRequest($filters, $fieldFilters, 50); $times = [];
    for ($iteration = 0; $iteration < 20; $iteration++) { $started = hrtime(true); $page = $search->search($request, $scope, $selected); $times[] = (hrtime(true) - $started) / 1e6; }
    sort($times); [$sql, $parameters] = $search->plan($request, $scope, $selected);
    if (count($page->rows) !== ($name === 'rare_email' ? 1 : 50)) { throw new RuntimeException('Scale query returned unexpected cardinality: ' . $name); }
    $explain = $db->rows('EXPLAIN ' . $sql, $parameters);
    // MariaDB execution evidence distinguishes optimizer work from row access;
    // EXPLAIN alone cannot explain slow planning with a large authorized scope.
    $analysis = $db->rows('ANALYZE FORMAT=JSON ' . $sql, $parameters);
    $analysis = json_decode($analysis[0]['ANALYZE'], true, 512, JSON_THROW_ON_ERROR);
    $results[$name] = ['rows' => count($page->rows), 'p50_ms' => $times[9], 'p95_ms' => $times[18], 'max_ms' => $times[19], 'explain' => $explain, 'analysis' => $analysis];
    echo $name . ': p95 ' . round($times[18], 2) . " ms\n";
}
$cursor = null; $seen = []; $started = hrtime(true);
for ($i = 0; $i < 100; $i++) {
    $page = $search->search(new Nicode\FormStudio\Search\SearchRequest(['form_id' => $formIds[0]], [], 50, $cursor), $scope);
    foreach ($page->rows as $row) { if (isset($seen[$row['id']])) { throw new RuntimeException('Scale cursor duplicated a row.'); } $seen[$row['id']] = true; }
    $cursor = $page->nextCursor;
}
if (count($seen) !== 5000) { throw new RuntimeException('Scale cursor did not return 5000 distinct rows.'); }
$report = ['timestamp' => gmdate(DATE_ATOM), 'database' => 'MariaDB 11.4.5', 'php' => PHP_VERSION, 'counts' => $counts, 'largest_form_rows' => 500000, 'results' => $results, 'cursor_5000_rows_ms' => (hrtime(true) - $started) / 1e6, 'php_peak_bytes' => memory_get_peak_usage(true), 'elapsed_seconds' => microtime(true) - $seedStarted, 'limitations' => ['Local synthetic data; no production SLO asserted.', 'Not a MySQL/PostgreSQL or browser benchmark.', 'Seed uses database-side generation, not the submission application pipeline.']];
$report['identity_schema_check_ms'] = $identityMigrationMs;
$report['innodb_buffer_pool_bytes'] = (int) $db->row("SHOW VARIABLES LIKE 'innodb_buffer_pool_size'")['Value'];
file_put_contents($root . '/build/scale-results.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Scale report written to build/scale-results.json.\n";
