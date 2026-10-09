<?php
declare(strict_types=1);

// Destructive acceptance only for the three dedicated lifecycle fixtures.
$root = dirname(__DIR__); $postgres = in_array('--postgresql', $argv, true); $mysql8 = in_array('--mysql8', $argv, true);
if ($postgres && $mysql8) { throw new InvalidArgumentException('Choose one fixture.'); }
$fixture = $postgres ? 'joomla-postgresql' : ($mysql8 ? 'joomla-mysql8' : 'joomla-lifecycle');
$databaseName = $postgres ? 'formstudio_joomla_pg' : ($mysql8 ? 'formstudio_joomla_mysql8' : 'formstudio_lifecycle');
$port = $postgres ? 13368 : ($mysql8 ? 13373 : 13367); $prefix = $postgres ? 'pg_' : ($mysql8 ? 'my_' : 'lc_');
$site = $root . '/build/' . $fixture; $phpOptions = $postgres ? ['-d', 'extension=pgsql', '-d', 'extension=pdo_pgsql'] : [];
$_SERVER['HTTP_HOST'] = '127.0.0.1'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
define('_JEXEC', 1); define('JPATH_BASE', $site); require $site . '/includes/defines.php'; require $site . '/includes/framework.php';
$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')->alias(Joomla\CMS\Session\Session::class, 'session.cli')->alias(Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(Joomla\CMS\Application\SiteApplication::class); Joomla\CMS\Factory::$application = $app;
if ($app->get('db') !== $databaseName || $app->get('host') !== '127.0.0.1:' . $port || $app->get('dbprefix') !== $prefix) { throw new RuntimeException('Refusing non-disposable purge fixture.'); }
$app->createExtensionNamespaceMap(); $app->loadLanguage($container->get(Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('en-GB', false));
$credentials = json_decode(file_get_contents($root . '/build/' . $fixture . '-test.json'), true, 512, JSON_THROW_ON_ERROR);
$admin = $container->get(Joomla\CMS\User\UserFactoryInterface::class)->loadUserByUsername($credentials['username']); unset($credentials); $app->loadIdentity($admin);
$driver = $container->get(Joomla\Database\DatabaseInterface::class);
$storageRoot = $root . '/build/purge-owned-' . $prefix; $exportsRoot = $root . '/build/purge-exports-' . $prefix;
foreach ([$storageRoot, $exportsRoot] as $directory) { if (!is_dir($directory)) { mkdir($directory, 0770, true); } file_put_contents($directory . '/unowned-sentinel.txt', 'must survive purge'); }
$configuration = json_encode(['captcha_mode' => 'none', 'allow_no_captcha' => 1, 'storage_path' => $storageRoot, 'export_path' => $exportsRoot], JSON_THROW_ON_ERROR);
$query = $driver->createQuery()->setQuery("UPDATE #__extensions SET params = :params WHERE type = 'component' AND element = 'com_nicode_form_studio'")->bind(':params', $configuration); $driver->setQuery($query)->execute();
$runtime = $app->bootComponent('com_nicode_form_studio')->runtime($app);
$db = $runtime->get(Nicode\FormStudio\Infrastructure\Database\Connection::class);
$forms = $runtime->get(Nicode\FormStudio\Infrastructure\Database\FormRepository::class);
$administration = $runtime->get(Nicode\FormStudio\Application\FormAdministration::class);
$jobs = $runtime->get(Nicode\FormStudio\Infrastructure\Database\JobRepository::class);
$package = (int) $db->row('SELECT extension_id FROM ' . $db->quote('#__extensions') . " WHERE type = 'package' AND element = 'pkg_nicode_form_studio'")['extension_id'];
$native = static function (string $phase, array $arguments) use ($site, $root, $fixture, $phpOptions): int {
    $process = proc_open([PHP_BINARY, ...$phpOptions, $site . '/cli/joomla.php', ...$arguments, '--no-interaction'], [0 => ['pipe', 'r'], 1 => ['file', $root . '/build/' . $fixture . '-purge-' . $phase . '.log', 'w'], 2 => ['file', $root . '/build/' . $fixture . '-purge-' . $phase . '-errors.log', 'w']], $pipes, $site);
    if (!is_resource($process)) { throw new RuntimeException('Native purge command unavailable.'); }
    fclose($pipes[0]); return proc_close($process);
};
$form = $administration->create('Purge ownership fixture', 'purge-' . bin2hex(random_bytes(5)), (int) $admin->id);
$field = Nicode\FormStudio\Domain\Uuid::create(); $draft = $forms->draft($form);
$draft['elements'] = [['uuid' => $field, 'type' => 'field']]; $draft['fields'] = [['uuid' => $field, 'type' => 'text', 'name' => 'answer', 'config' => []]];
$draft['security'] = ['captcha' => ['mode' => 'none']];
$revision = $administration->save($form, 0, $draft, (int) $admin->id); $version = $administration->publish($form, $revision, (int) $admin->id);
$submission = $runtime->get(Nicode\FormStudio\Infrastructure\Database\SubmissionRepository::class)->persist($form, $version, $forms->version($form, $version), [$field => 'synthetic purge response'], hash('sha256', random_bytes(32)));
$storage = $runtime->get(Nicode\FormStudio\Registry\StorageProviderRegistry::class)->get('local');
$uploadJournal = $runtime->get(Nicode\FormStudio\Infrastructure\Database\UploadJournal::class);
$interrupted = $uploadJournal->reserve($form, $storage);
$stream = fopen('php://temp', 'w+b'); fwrite($stream, 'interrupted owned bytes'); rewind($stream); $storage->putReserved($interrupted->key, $stream, 100); fclose($stream);
$paused = $uploadJournal->reserve($form, $storage);
$stream = fopen('php://temp', 'w+b'); fwrite($stream, 'owned purge bytes'); rewind($stream); $stored = $storage->put($stream, 100); fclose($stream);
$db->insert('submission_files', ['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'submission_id' => $submission->id, 'field_uuid' => $field, 'provider' => 'local', 'storage_key' => $stored->key, 'original_name' => 'purge.txt', 'mime' => 'text/plain', 'size_bytes' => $stored->size, 'checksum' => $stored->checksum, 'created_at' => gmdate('Y-m-d H:i:s')]);
$export = $jobs->enqueue('export-csv', ['form_id' => $form], (int) $admin->id); $exportUuid = $jobs->get($export)['uuid'];
$runtime->get(Nicode\FormStudio\Export\ExportWorkspace::class)->append($exportUuid, 0, ['answer'], [['synthetic purge export']], static fn () => null);
$db->execute('UPDATE ' . $db->table('jobs') . " SET state = 'completed', artifact_key = :key, expires_at = :expires WHERE id = :id", [':id' => $export, ':key' => $exportUuid, ':expires' => gmdate('Y-m-d H:i:s', time() + 86400)]);
$jsonExports=[];
foreach([true,false] as $jsonComplete) {
    $jsonExport=$jobs->enqueue('export-json',['form_id'=>$form],(int)$admin->id); $jsonUuid=$jobs->get($jsonExport)['uuid'];
    $runtime->get(Nicode\FormStudio\Export\ExportWorkspace::class)->appendJson($jsonUuid,0,[['reference'=>'synthetic','values'=>['answer'=>'owned JSON purge bytes']]],$jsonComplete,static fn()=>null);
    $db->execute('UPDATE '.$db->table('jobs').' SET state = :state, artifact_key = :key, expires_at = :expires WHERE id = :id',[':id'=>$jsonExport,':state'=>$jsonComplete?'completed':'running',':key'=>$jsonComplete?$jsonUuid:null,':expires'=>gmdate('Y-m-d H:i:s',time()+86400)]);
    $jsonExports[$jsonExport]=$jsonUuid;
}
$purge = $runtime->get(Nicode\FormStudio\Application\PackagePurge::class); $actor = (int) $admin->id;
try { $purge->review(0); throw new RuntimeException('Guest reviewed purge.'); } catch (DomainException) {}
$review = $purge->review($actor);
if ($review['state'] !== 'preserve' || $review['forms'] < 1 || $review['submission_files'] !== 1 || $review['bytes'] !== $stored->size) { throw new RuntimeException('Purge review failed.'); }
try { $purge->prepare($actor, $review['confirmation'], 'wrong phrase'); throw new RuntimeException('Purge accepted missing phrase.'); } catch (Nicode\FormStudio\Domain\ConcurrentEdit) {}
$purgeJob = $purge->prepare($actor, $review['confirmation'], 'DELETE FORMSTUDIO DATA');
foreach($jsonExports as $jsonExport=>$jsonUuid) {
    if($jobs->get($jsonExport)['state']!=='cancelled') { throw new RuntimeException('Purge did not revoke JSON export.'); }
}
$stream = fopen('php://temp', 'w+b'); fwrite($stream, 'late bytes'); rewind($stream);
try { $uploadJournal->write($paused, $storage, $stream, 100); throw new RuntimeException('Paused writer crossed purge fence.'); } catch (DomainException) {} finally { fclose($stream); }
if ($storage->exists($paused->key)) { throw new RuntimeException('Purge allowed late object creation.'); }
try { $forms->create('Late form', 'late-' . bin2hex(random_bytes(5)), $actor); throw new RuntimeException('Purge accepted new form.'); } catch (DomainException) {}
try { $runtime->get(Nicode\FormStudio\Security\PublicAccess::class)->assert($forms->get($form), [1], 'en-GB', time()); throw new RuntimeException('Purge left public admission open.'); } catch (OutOfBoundsException) {}
if ($native('early-uninstall', ['extension:remove', (string) $package]) === 0) { throw new RuntimeException('Installer removed code before cleanup.'); }
if (!is_file($site . '/libraries/nicode_form_studio/autoload.php') || !$storage->exists($stored->key)) { throw new RuntimeException('Rejected uninstall changed code or private objects.'); }
$worker = $runtime->get(Nicode\FormStudio\Jobs\JobWorker::class);
$stream = fopen('php://temp', 'w+b'); fwrite($stream, 'cleanup failure bytes'); rewind($stream); $blockedObject = $storage->put($stream, 100); fclose($stream);
$blockedCleanup = $jobs->enqueue('file-cleanup', ['objects' => [['provider' => 'fixture.missing', 'storage_key' => $blockedObject->key]]], $actor);
for ($tick = 0; $tick < 1000; $tick++) {
    $worker->tick(50);
    $db->execute('UPDATE ' . $db->table('jobs') . ' SET available_at = :now WHERE id = :id AND state = :state', [':now' => gmdate('Y-m-d H:i:s'), ':id' => $purgeJob, ':state' => 'retryable']);
    if ($jobs->get($blockedCleanup)['state'] === 'failed' && $db->row('SELECT id FROM ' . $db->table('forms') . ' LIMIT 1') === null) { break; }
}
if ($purge->review($actor)['state'] !== 'preparing' || !$storage->exists($blockedObject->key)) { throw new RuntimeException('Failed cleanup was discarded or purge became ready early.'); }
if ($native('cleanup-blocked', ['extension:remove', (string) $package]) === 0) { throw new RuntimeException('Installer ignored a failed cleanup outbox.'); }
// Repair the deliberately injected fixture provider reference, then exercise the
// real administrative resume path; production UI never accepts object keys.
$db->execute('UPDATE ' . $db->table('jobs') . ' SET parameters = :parameters WHERE id = :id', [':id' => $blockedCleanup, ':parameters' => Nicode\FormStudio\Domain\CanonicalJson::encode(['objects' => [['provider' => 'local', 'storage_key' => $blockedObject->key]]])]);
$resumeReview = $purge->review($actor); $purge->prepare($actor, $resumeReview['confirmation'], 'DELETE FORMSTUDIO DATA');
for ($tick = 0; $tick < 2000 && $jobs->get($purgeJob)['state'] !== 'completed'; $tick++) {
    $worker->tick(50);
    // Test clock acceleration only: no production polling sleeps or lease changes.
    $db->execute('UPDATE ' . $db->table('jobs') . ' SET available_at = :now WHERE id = :id AND state = :state', [':now' => gmdate('Y-m-d H:i:s'), ':id' => $purgeJob, ':state' => 'retryable']);
}
if ($purge->review($actor)['state'] !== 'ready' || $storage->exists($stored->key) || $storage->exists($blockedObject->key) || is_file($exportsRoot . '/' . $exportUuid . '.csv')) { throw new RuntimeException('Purge failed to clean owned files/exports.'); }
foreach($jsonExports as $jsonExport=>$jsonUuid) {
    if(is_file($exportsRoot.'/'.$jsonUuid.'.json') || $jobs->get($jsonExport)['result_code']!=='artifact_expired') { throw new RuntimeException('Purge left completed or partial JSON artifact.'); }
}
// Isolate the installer guard: all other owned records/outboxes are already clean.
$jsonExport=array_key_first($jsonExports); $jsonUuid=$jsonExports[$jsonExport];
$runtime->get(Nicode\FormStudio\Export\ExportWorkspace::class)->appendJson($jsonUuid,0,[['reference'=>'guard-only-fixture']],true,static fn()=>null);
$db->execute('UPDATE '.$db->table('jobs').' SET result_code = NULL, artifact_key = :key WHERE id = :id',[':id'=>$jsonExport,':key'=>$jsonUuid]);
if($native('json-only-blocked',['extension:remove',(string)$package])===0) { throw new RuntimeException('Installer ignored the sole remaining JSON artifact.'); }
if(!is_file($site.'/libraries/nicode_form_studio/autoload.php') || !is_file($exportsRoot.'/'.$jsonUuid.'.json')) { throw new RuntimeException('JSON guard refusal changed owned code or bytes.'); }
$jsonCleanup=$jobs->enqueue('export-cleanup',[],$actor);
for($tick=0;$tick<1000 && $jobs->get($jsonCleanup)['state']!=='completed';$tick++) { $worker->tick(50); }
if($jobs->get($jsonCleanup)['state']!=='completed' || is_file($exportsRoot.'/'.$jsonUuid.'.json') || $jobs->get($jsonExport)['artifact_key']!==null) { throw new RuntimeException('JSON guard fixture did not clean up normally.'); }
if (!is_file($storageRoot . '/unowned-sentinel.txt') || !is_file($exportsRoot . '/unowned-sentinel.txt')) { throw new RuntimeException('Purge touched unowned files.'); }
if ($storage->exists($interrupted->key) || $db->row('SELECT id FROM ' . $db->table('upload_staging') . ' LIMIT 1') !== null) { throw new RuntimeException('Purge lost an interrupted upload.'); }
if ($native('uninstall', ['extension:remove', (string) $package]) !== 0) { throw new RuntimeException('Prepared native purge uninstall failed.'); }
$remaining = array_values(array_filter($driver->getTableList(), static fn (string $name): bool => str_starts_with($name, $prefix . 'nicode_form_studio_')));
if ($remaining !== []) { throw new RuntimeException('Purge left extension schema.'); }
if ($native('reinstall', ['extension:install', '--path=' . $root . '/build/development-package/pkg_nicode_form_studio.zip']) !== 0) { throw new RuntimeException('Clean reinstall after purge failed.'); }
if ((int) $db->row('SELECT COUNT(*) AS total FROM ' . $db->table('forms'))['total'] !== 0 || (new Nicode\FormStudio\Infrastructure\Database\PurgeState($db))->active()) { throw new RuntimeException('Purge reinstall retained data or purge marker.'); }
file_put_contents($root . '/build/' . $fixture . '-purge-results.json', json_encode(['passed' => true, 'database' => $driver->getVersion(), 'package_sha256' => hash_file('sha256', $root . '/build/development-package/pkg_nicode_form_studio.zip'), 'early_uninstall_blocked' => true, 'owned_files_removed' => true, 'exports_removed' => true, 'json_complete_partial_removed' => true, 'json_only_uninstall_blocked' => true, 'unowned_files_preserved' => true, 'schema_removed' => true, 'clean_reinstall' => true, 'timestamp' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Native purge: confirmation/admission, blocked early/JSON-only uninstall, bounded cleanup, CSV and completed/partial JSON removed, unowned objects preserved, schema dropped and clean reinstall passed.\n";
