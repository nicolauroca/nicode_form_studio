<?php
declare(strict_types=1);

// Dedicated official Joomla fixture. Never run against the HTTP acceptance site.
$root = dirname(__DIR__); $postgres = in_array('--postgresql', $argv, true);
$mysql8 = in_array('--mysql8', $argv, true);
if ($postgres && $mysql8) { throw new InvalidArgumentException('Choose one database fixture.'); }
$fixtureName = $postgres ? 'joomla-postgresql' : ($mysql8 ? 'joomla-mysql8' : 'joomla-lifecycle');
$site = $root . '/build/' . $fixtureName;
$prefix = $postgres ? 'pg_' : ($mysql8 ? 'my_' : 'lc_'); $reportPrefix = $postgres ? 'postgresql-lifecycle' : ($mysql8 ? 'mysql8-lifecycle' : 'lifecycle');
$databaseName = $postgres ? 'formstudio_joomla_pg' : ($mysql8 ? 'formstudio_joomla_mysql8' : 'formstudio_lifecycle');
$databaseHost = $postgres ? '127.0.0.1:13368' : ($mysql8 ? '127.0.0.1:13373' : '127.0.0.1:13367');
$phpOptions = $postgres ? ['-d', 'extension=pgsql', '-d', 'extension=pdo_pgsql'] : [];
$_SERVER['HTTP_HOST'] = '127.0.0.1'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
define('_JEXEC', 1); define('JPATH_BASE', $site);
require $site . '/includes/defines.php'; require $site . '/includes/framework.php';
$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')->alias(Joomla\CMS\Session\Session::class, 'session.cli')->alias(Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(Joomla\CMS\Application\SiteApplication::class); Joomla\CMS\Factory::$application = $app;
if ($app->get('db') !== $databaseName || $app->get('host') !== $databaseHost || $app->get('dbprefix') !== $prefix) { throw new RuntimeException('Refusing non-isolated lifecycle fixture.'); }
$app->createExtensionNamespaceMap(); $app->loadLanguage($container->get(Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('en-GB', false));
$credentials = json_decode(file_get_contents($root . '/build/' . $fixtureName . '-test.json'), true, 512, JSON_THROW_ON_ERROR);
$admin = $container->get(Joomla\CMS\User\UserFactoryInterface::class)->loadUserByUsername($credentials['username']); unset($credentials); $app->loadIdentity($admin);
$runtime = $app->bootComponent('com_nicode_form_studio')->runtime($app);
$db = $runtime->get(Nicode\FormStudio\Infrastructure\Database\Connection::class);
$driver = $container->get(Joomla\Database\DatabaseInterface::class);
if ((int) ($db->row('SELECT enabled FROM ' . $db->quote('#__extensions') . " WHERE type = 'plugin' AND folder = 'behaviour' AND element = 'compat6'")['enabled'] ?? 0) !== 0) { throw new RuntimeException('Lifecycle acceptance requires compatibility plugin disabled.'); }
$tables = array_values(array_filter($driver->getTableList(), static fn (string $name): bool => str_starts_with($name, $prefix . 'nicode_form_studio_')));
if (count($tables) !== 30) { throw new RuntimeException('Native installer schema table count: ' . count($tables)); }
$reflection = new ReflectionClass(Nicode\FormStudio\Application\FormAdministration::class);
if (!str_starts_with(str_replace('\\', '/', $reflection->getFileName()), str_replace('\\', '/', $site) . '/libraries/nicode_form_studio/')) { throw new RuntimeException('Lifecycle test bypassed the installed library.'); }
$extensions = $db->rows('SELECT extension_id, type, element, package_id FROM ' . $db->quote('#__extensions') . " WHERE element IN ('pkg_nicode_form_studio', 'com_nicode_form_studio', 'mod_nicode_form_studio', 'nicode_form_studio')");
$package = null; foreach ($extensions as $entry) { if ($entry['type'] === 'package') { $package = (int) $entry['extension_id']; } }
if ($package === null || count($extensions) !== 6) { throw new RuntimeException('Package constituents missing.'); }
foreach ($extensions as $entry) { if ($entry['type'] !== 'package' && (int) $entry['package_id'] !== $package) { throw new RuntimeException('Child package dependency missing.'); } }
$administration = $runtime->get(Nicode\FormStudio\Application\FormAdministration::class);
$form = $administration->create('Lifecycle acceptance', 'lifecycle-' . bin2hex(random_bytes(4)), (int) $admin->id);
$draft = $administration->edit($form, (int) $admin->id)['draft'];
$field = Nicode\FormStudio\Domain\Uuid::create();
$draft['elements'] = [['uuid' => $field, 'type' => 'field', 'parent_uuid' => null]];
$draft['fields'] = [['uuid' => $field, 'type' => 'text', 'name' => 'answer', 'index' => true, 'config' => ['label' => 'Lifecycle answer']]];
$revision = $administration->save($form, 0, $draft, (int) $admin->id);
$version = $administration->publish($form, $revision, (int) $admin->id);
$forms = $runtime->get(Nicode\FormStudio\Infrastructure\Database\FormRepository::class);
$spec = $forms->version($form, $version);
$submissions = $runtime->get(Nicode\FormStudio\Infrastructure\Database\SubmissionRepository::class);
$submission = $submissions->persist($form, $version, $spec, [$field => 'Synthetic lifecycle value'], hash('sha256', random_bytes(32)));
$before = $submissions->get($form, $submission->id)['canonical_payload']; $asset = (int) $forms->get($form)['asset_id'];
$db->execute('UPDATE ' . $db->quote('#__extensions') . " SET enabled = 0 WHERE type = 'plugin' AND folder = 'extension' AND element = 'nicode_form_studio'");
$process = proc_open([PHP_BINARY, ...$phpOptions, $site . '/cli/joomla.php', 'extension:install', '--path=' . $root . '/build/development-package/pkg_nicode_form_studio.zip', '--no-interaction'], [0 => ['pipe', 'r'], 1 => ['file', $root . '/build/' . $reportPrefix . '-update.log', 'w'], 2 => ['file', $root . '/build/' . $reportPrefix . '-update-errors.log', 'w']], $pipes, $site);
if (!is_resource($process)) { throw new RuntimeException('Unable to start native update.'); }
fclose($pipes[0]); if (proc_close($process) !== 0) { throw new RuntimeException('Native update failed; inspect ignored fixture logs.'); }
if ((int) $db->row('SELECT enabled FROM ' . $db->quote('#__extensions') . " WHERE type = 'plugin' AND folder = 'extension' AND element = 'nicode_form_studio'")['enabled'] !== 0) { throw new RuntimeException('Update silently enabled an administratively disabled audit plugin.'); }
$db->execute('UPDATE ' . $db->quote('#__extensions') . " SET enabled = 1 WHERE type = 'plugin' AND folder = 'extension' AND element = 'nicode_form_studio'");
if ($before !== $submissions->get($form, $submission->id)['canonical_payload'] || $spec->hash !== $forms->version($form, $version)->hash || $asset !== (int) $forms->get($form)['asset_id']) { throw new RuntimeException('Native update changed canonical history or form ACL identity.'); }
$rootRules = '{"formstudio.submissions.export":{"2":0}}'; $formRules = '{"formstudio.submissions.view_sensitive":{"2":0}}';
$db->execute('UPDATE ' . $db->quote('#__assets') . ' SET rules = :rules WHERE name = :name', [':rules' => $rootRules, ':name' => 'com_nicode_form_studio']);
$db->execute('UPDATE ' . $db->quote('#__assets') . ' SET rules = :rules WHERE id = :id', [':rules' => $formRules, ':id' => $asset]);
$params = '{"captcha_mode":"none","allow_no_captcha":1,"lifecycle_marker":"preserved"}';
$db->execute('UPDATE ' . $db->quote('#__extensions') . ' SET params = :params WHERE element = :element', [':params' => $params, ':element' => 'com_nicode_form_studio']);
$orphan = $forms->create('Preserved inaccessible orphan', 'orphan-' . bin2hex(random_bytes(4)), (int) $admin->id);
$nativeCommand = static function (string $phase, array $arguments) use ($root, $site, $phpOptions, $reportPrefix): int {
    $process = proc_open([PHP_BINARY, ...$phpOptions, $site . '/cli/joomla.php', ...$arguments, '--no-interaction'], [0 => ['pipe', 'r'], 1 => ['file', $root . '/build/' . $reportPrefix . '-' . $phase . '.log', 'w'], 2 => ['file', $root . '/build/' . $reportPrefix . '-' . $phase . '-errors.log', 'w']], $pipes, $site);
    if (!is_resource($process)) { throw new RuntimeException('Unable to run native lifecycle command.'); }
    fclose($pipes[0]); return proc_close($process);
};
$componentId = (int) $db->row('SELECT extension_id FROM ' . $db->quote('#__extensions') . " WHERE element = 'com_nicode_form_studio'")['extension_id'];
if ($nativeCommand('child-removal', ['extension:remove', (string) $componentId]) === 0) { throw new RuntimeException('Required package child could be removed independently.'); }
if ($nativeCommand('preserve-uninstall', ['extension:remove', (string) $package]) !== 0) { throw new RuntimeException('Native preserve uninstall failed.'); }
if ($db->row('SELECT id FROM ' . $db->quote('#__assets') . " WHERE name = 'com_nicode_form_studio'") !== null) { throw new RuntimeException('Core uninstall left the active component asset.'); }
if ($before !== $submissions->get($form, $submission->id)['canonical_payload'] || $db->row('SELECT state_json FROM ' . $db->table('installation_state') . " WHERE state_key = 'component'") === null) { throw new RuntimeException('Preserve uninstall lost canonical data or its restoration snapshot.'); }
if ($nativeCommand('preserve-reinstall', ['extension:install', '--path=' . $root . '/build/development-package/pkg_nicode_form_studio.zip']) !== 0) { throw new RuntimeException('Native preserve reinstall failed.'); }
$restoredForm = $forms->get($form); $restoredAsset = $db->row('SELECT name, rules, parent_id FROM ' . $db->quote('#__assets') . ' WHERE id = :id', [':id' => (int) $restoredForm['asset_id']]);
$restoredRoot = $db->row('SELECT id, rules FROM ' . $db->quote('#__assets') . " WHERE name = 'com_nicode_form_studio'");
if ($restoredAsset['name'] !== 'com_nicode_form_studio.form.' . $form || $restoredAsset['rules'] !== $formRules || (int) $restoredAsset['parent_id'] !== (int) $restoredRoot['id'] || $restoredRoot['rules'] !== $rootRules) { throw new RuntimeException('Reinstall changed explicit ACL denials or asset parentage.'); }
if ($forms->get($orphan)['asset_id'] !== null || $before !== $submissions->get($form, $submission->id)['canonical_payload'] || $spec->hash !== $forms->version($form, $version)->hash) { throw new RuntimeException('Reinstall changed privacy or immutable history.'); }
if ($db->row('SELECT params FROM ' . $db->quote('#__extensions') . " WHERE element = 'com_nicode_form_studio'")['params'] !== $params) { throw new RuntimeException('Reinstall lost component configuration.'); }
file_put_contents($root . '/build/' . $reportPrefix . '-results.json', json_encode(['database' => $driver->getVersion(), 'package_sha256' => hash_file('sha256', $root . '/build/development-package/pkg_nicode_form_studio.zip'), 'installed_schema_tables' => count($tables), 'package_children' => 5, 'installed_runtime' => true, 'compatibility_plugin' => false, 'same_version_update_preserved_history' => true, 'preserve_reinstall' => true, 'acl_denials_preserved' => true, 'orphan_remains_inaccessible' => true, 'configuration_preserved' => true, 'child_removal_blocked' => true, 'form_id' => $form, 'version_id' => $version, 'submission_id' => $submission->id, 'asset_id' => (int) $restoredForm['asset_id'], 'timestamp' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Dedicated native lifecycle: 30 tables, five package children, installed runtime publication/persistence and history-preserving same-version update verified.\n";
echo "Native preserve uninstall/reinstall: canonical history, explicit ACL denials, configuration and inaccessible orphan state preserved; direct child removal blocked.\n";

$deleteForm = $administration->create('Native delete lifecycle', 'native-delete-' . bin2hex(random_bytes(5)), (int) $admin->id);
$deleteAsset = (int) $forms->get($deleteForm)['asset_id'];
$administration->deactivate($deleteForm, 0, (int) $admin->id, 'trashed');
$deletion = $runtime->get(Nicode\FormStudio\Application\FormDeletion::class);
$review = $deletion->review($deleteForm, (int) $admin->id);
$deleteJob = $deletion->enqueue($deleteForm, 1, (int) $admin->id, $review['uuid']);
$deleteJobs = $runtime->get(Nicode\FormStudio\Infrastructure\Database\JobRepository::class);
for ($chunk = 0; $chunk < 30 && $deleteJobs->get($deleteJob)['state'] !== 'completed'; $chunk++) {
    $db->execute('UPDATE ' . $db->table('jobs') . " SET available_at = '1000-01-01 00:00:00' WHERE id = :id", [':id' => $deleteJob]);
    $runtime->get(Nicode\FormStudio\Jobs\JobWorker::class)->tick(2);
}
if ($deleteJobs->get($deleteJob)['state'] !== 'completed' || $db->row('SELECT id FROM ' . $db->table('forms') . ' WHERE id = :id', [':id' => $deleteForm]) || $db->row('SELECT id FROM ' . $db->quote('#__assets') . ' WHERE id = :id', [':id' => $deleteAsset])) { throw new RuntimeException('Native form deletion failed.'); }
if ($before !== $submissions->get($form, $submission->id)['canonical_payload']) { throw new RuntimeException('Deletion changed another form.'); }
$reportPath = $root . '/build/' . $reportPrefix . '-results.json';
$report = json_decode(file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR); $report['native_form_deletion'] = true;
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Native permanent form deletion: bounded installed worker, real Joomla asset removal and unrelated preserved history verified.\n";
