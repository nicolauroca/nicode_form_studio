<?php
declare(strict_types=1);

$root = dirname(__DIR__); $site = $root . '/build/joomla-6.0.0';
define('_JEXEC', 1); define('JPATH_BASE', $site);
require $site . '/includes/defines.php'; require $site . '/includes/framework.php';
require $root . '/src/lib_nicode_form_studio/autoload.php';
$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')->alias(Joomla\CMS\Session\Session::class, 'session.cli')->alias(Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(Joomla\Console\Application::class); Joomla\CMS\Factory::$application = $app;
$app->createExtensionNamespaceMap();
if ($app->get('db') !== 'formstudio_joomla' || $app->get('host') !== '127.0.0.1:13367') { throw new RuntimeException('Refusing non-isolated Joomla ACL test.'); }
$database = $container->get(Joomla\Database\DatabaseInterface::class); $events = $container->get(Joomla\Event\DispatcherInterface::class);
foreach (Joomla\Database\DatabaseDriver::splitSql(file_get_contents($root . '/src/com_nicode_form_studio/administrator/sql/mysql/install.sql')) as $statement) { if (trim($statement) !== '') { $database->setQuery($statement)->execute(); } }
$db = new Nicode\FormStudio\Infrastructure\Database\Connection($database);
$component = new Joomla\CMS\Table\Asset($database, $events);
if (!$component->loadByName('com_nicode_form_studio')) { $component->name = 'com_nicode_form_studio'; $component->title = 'Nicode Form Studio'; $component->setLocation(1, 'last-child'); }
$originalRules = $component->rules ?: '{}';
$component->rules = '{"formstudio.submissions.view":{"2":1}}'; if (!$component->check() || !$component->store()) { throw new RuntimeException('Unable to prepare ACL asset.'); }
try {
    $fields = new Nicode\FormStudio\Registry\FieldTypeRegistry(); Nicode\FormStudio\Field\CoreFieldTypes::register($fields);
    $compiler = new Nicode\FormStudio\Compiler\FormCompiler($fields, new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry());
    $forms = new Nicode\FormStudio\Infrastructure\Database\FormRepository($db, $compiler);
    $form = $forms->create('ACL fixture', 'acl-' . bin2hex(random_bytes(5)), 1);
    $manager = new Nicode\FormStudio\Infrastructure\Joomla\AssetManager($database, $events, $db);
    $users = $container->get(Joomla\CMS\User\UserFactoryInterface::class);
    $authorization = new Nicode\FormStudio\Infrastructure\Joomla\Authorization($users, $db);
    $credentials = json_decode(file_get_contents($root . '/build/joomla-test.json'), true, 512, JSON_THROW_ON_ERROR);
    $admin = $users->loadUserByUsername($credentials['username']);
    if ($authorization->allows((int) $admin->id, $form, 'formstudio.submissions.view')) { throw new RuntimeException('Missing form asset did not deny access.'); }
    $assetId = $manager->attach($form);
    if (!$authorization->allows((int) $admin->id, $form, 'formstudio.submissions.view') || $authorization->allows(0, $form, 'formstudio.submissions.view')) { throw new RuntimeException('Joomla admin or guest authorization failed.'); }
    $suffix = bin2hex(random_bytes(5)); $visitor = new Joomla\CMS\User\User();
    $password = bin2hex(random_bytes(24));
    $userData = ['name' => 'ACL test', 'username' => 'acl-test-' . $suffix, 'email' => 'acl-' . $suffix . '@example.test', 'password' => $password, 'password2' => $password, 'groups' => [2], 'block' => 0];
    if (!$visitor->bind($userData) || !$visitor->save()) { throw new RuntimeException('Unable to create isolated ACL fixture user.'); }
    Joomla\CMS\Access\Access::clearStatics();
    if (!$authorization->allows((int) $visitor->id, $form, 'formstudio.submissions.view') || $authorization->allows((int) $visitor->id, $form, 'formstudio.submissions.export')) { throw new RuntimeException('Inherited allow or independent export permission failed.'); }
    $child = new Joomla\CMS\Table\Asset($database, $events); $child->load($assetId); $child->rules = '{"formstudio.submissions.view":{"2":0}}'; $child->store(); Joomla\CMS\Access\Access::clearStatics();
    if ($authorization->allows((int) $visitor->id, $form, 'formstudio.submissions.view')) { throw new RuntimeException('Form-specific deny was ignored.'); }
    echo "Real Joomla ACL: asset attachment, inherited allow, per-form deny, independent export, admin and guest verified.\n";
    $permissions = new Nicode\FormStudio\Infrastructure\Joomla\FormPermissions($db, $authorization);
    $permissionView = $permissions->read($form, (int) $admin->id, 2);
    if ($permissionView['permissions']['formstudio.submissions.view']['direct'] !== false || $permissionView['permissions']['formstudio.submissions.view']['effective'] !== false) { throw new RuntimeException('Native direct/effective permissions are incorrect.'); }
    try { $permissions->update($form, 0, $permissionView['rules_hash'], (int) $visitor->id, 2, ['formstudio.submissions.export' => true]); throw new RuntimeException('Non-administrator changed form permissions.'); } catch (DomainException) {}
    $permissions->update($form, 0, $permissionView['rules_hash'], (int) $admin->id, 2, ['formstudio.submissions.export' => true]);
    if (!$authorization->allows((int) $visitor->id, $form, 'formstudio.submissions.export') || $authorization->allows((int) $visitor->id, $form, 'formstudio.submissions.view')) { throw new RuntimeException('Permission edit replaced unrelated rules or failed to clear ACL cache.'); }
    $permissionView = $permissions->read($form, (int) $admin->id, 2);
    try { $permissions->update($form, 0, $permissionView['rules_hash'], (int) $admin->id, 2, ['formstudio.submissions.export' => null]); throw new RuntimeException('Stale permission revision accepted.'); } catch (Nicode\FormStudio\Domain\ConcurrentEdit) {}
    $db->execute('UPDATE ' . $db->quote('#__assets') . ' SET rules = :rules WHERE id = :id', [':rules' => '{"formstudio.submissions.view":{"2":0},"formstudio.submissions.export":{"2":1},"core.edit":{"3":0}}', ':id' => $assetId]);
    try { $permissions->update($form, 1, $permissionView['rules_hash'], (int) $admin->id, 2, ['formstudio.submissions.export' => null]); throw new RuntimeException('External native ACL edit was overwritten.'); } catch (Nicode\FormStudio\Domain\ConcurrentEdit) {}
    if ((int) $forms->get($form)['draft_revision'] !== 1) { throw new RuntimeException('Failed ACL comparison consumed the edit revision.'); }
    $permissionView = $permissions->read($form, (int) $admin->id, 2);
    $permissions->update($form, 1, $permissionView['rules_hash'], (int) $admin->id, 2, ['formstudio.submissions.export' => null]);
    $permissionAudit = $db->rows('SELECT actor_id, safe_metadata FROM ' . $db->table('audit_log') . " WHERE form_id = :form AND event_type = 'form.permissions' ORDER BY id", [':form' => $form]);
    if (count($permissionAudit) !== 2) { throw new RuntimeException('Permission audit included denied/stale writes or lost successful changes.'); }
    foreach ($permissionAudit as $entry) { if ((int) $entry['actor_id'] !== (int) $admin->id || json_decode($entry['safe_metadata'], true, 32, JSON_THROW_ON_ERROR)['group_id'] !== 2) { throw new RuntimeException('Permission audit lost actor or group identity.'); } }
    if ($authorization->allows((int) $visitor->id, $form, 'formstudio.submissions.export')) { throw new RuntimeException('Inherited permission reset failed.'); }
    echo "Native form permission editing: privileged actor, direct/effective rules, independent actions, inheritance reset and external-edit conflicts verified.\n";
    $captcha = new Nicode\FormStudio\Infrastructure\Joomla\CaptchaAdapter(new Joomla\CMS\Captcha\CaptchaRegistry(), new Nicode\FormStudio\Security\CaptchaPolicy('none'), null);
    $administration = new Nicode\FormStudio\Application\FormAdministration($forms, $db, $authorization->allows(...), $manager->attach(...), $captcha);
    $managed = $administration->create('Managed fixture', 'managed-' . $suffix, (int) $admin->id);
    $managedDraft = $administration->edit($managed, (int) $admin->id)['draft'];
    $managedDraft['name'] = 'Renamed managed fixture';
    $administration->save($managed, 0, $managedDraft, (int) $admin->id);
    $managedAsset = new Joomla\CMS\Table\Asset($database, $events); $managedAsset->loadByName('com_nicode_form_studio.form.' . $managed);
    if ($managedAsset->title !== 'Renamed managed fixture') { throw new RuntimeException('Administrative save did not synchronize real Joomla asset.'); }
    try { $administration->edit($managed, (int) $visitor->id); throw new RuntimeException('Ordinary registered user opened the editor.'); } catch (DomainException) {}
    $failedForm = null;
    $treeBeforeFailure = $db->rows('SELECT id, parent_id, lft, rgt, level FROM ' . $db->quote('#__assets') . ' ORDER BY id');
    $failing = new Nicode\FormStudio\Application\FormAdministration($forms, $db, $authorization->allows(...), static function (int $id) use ($manager, &$failedForm): void {
        $failedForm = $id; $manager->attach($id); throw new RuntimeException('Failure after real asset creation.');
    }, $captcha);
    try { $failing->create('Rollback fixture', 'rollback-' . $suffix, (int) $admin->id); throw new LogicException('Failure ignored.'); }
    catch (RuntimeException $error) { if ($error->getMessage() !== 'Failure after real asset creation.') { throw $error; } }
    if ($db->row('SELECT id FROM ' . $db->table('forms') . ' WHERE id = :id', [':id' => $failedForm]) !== null || $db->row('SELECT id FROM ' . $db->quote('#__assets') . ' WHERE name = :name', [':name' => 'com_nicode_form_studio.form.' . $failedForm]) !== null) { throw new RuntimeException('Real Joomla form/asset transaction was not atomic.'); }
    if ($treeBeforeFailure !== $db->rows('SELECT id, parent_id, lft, rgt, level FROM ' . $db->quote('#__assets') . ' ORDER BY id')) { throw new RuntimeException('Asset rollback changed Joomla nested-set boundaries.'); }
    echo "Real Joomla administrative service: asset creation/rename, editor ACL and asset/form rollback verified.\n";
    $component->rules = '{"core.manage":{"2":1},"formstudio.submissions.view":{"2":1}}'; $component->store(); Joomla\CMS\Access\Access::clearStatics();
    $trustedRows = $db->rows('SELECT f.id, a.name AS asset_name FROM ' . $db->table('forms') . ' f JOIN ' . $db->quote('#__assets') . ' a ON a.id = f.asset_id WHERE f.id IN (:allowed, :denied)', [':allowed' => $managed, ':denied' => $form]);
    $responseCapabilities = $authorization->submissionCapabilities((int) $visitor->id, $trustedRows);
    if (!isset($responseCapabilities[$managed]) || isset($responseCapabilities[$form]) || $responseCapabilities[$managed]['formstudio.submissions.view_sensitive'] || $authorization->formCapabilities((int) $visitor->id, $trustedRows) !== []) { throw new RuntimeException('Response-only ACL scope inherited editor access or ignored form deny.'); }
    echo "Response-only administrator scope: no editor permission required, form deny and sensitive permission respected.\n";
    $responses = new Nicode\FormStudio\Infrastructure\Database\SubmissionRepository($db, new Nicode\FormStudio\Search\IndexProjector($fields), random_bytes(32));
    $responseReader = new Nicode\FormStudio\Application\SubmissionReader($responses, $forms, $db, $authorization->allows(...));
    $responseSearch = new Nicode\FormStudio\Search\SqlSearchProvider($db, $fields, new Nicode\FormStudio\Search\CursorCodec(random_bytes(32)));
    $responseExplorer = new Nicode\FormStudio\Application\SubmissionExplorer($db, $forms, $responseSearch, $responseReader, $authorization->allows(...), $authorization->submissionCapabilities(...), $fields);
    $savedViews = new Nicode\FormStudio\Application\SavedSubmissionViews($db, $responseExplorer, $authorization->allows(...));
    $savedView = $savedViews->create((int) $visitor->id, 'Revocation fixture', ['filters' => ['form_id' => $managed], 'fields' => [], 'columns' => []]);
    $dashboard = new Nicode\FormStudio\Application\OperationsDashboard($db, $authorization->allows(...), $authorization->formCapabilities(...), $authorization->submissionCapabilities(...), static function (): array { throw new RuntimeException('Response-only actor triggered authoring diagnostics.'); }, time(...));
    $dashboardBefore = $dashboard->report((int) $visitor->id);
    if ($dashboardBefore['modified'] !== [] || $dashboardBefore['diagnosed_forms'] !== 0 || $dashboardBefore['metrics']['forms_published'] !== 0 || $dashboardBefore['technical_errors'] !== null || $dashboardBefore['disabled_sources'] !== null) { throw new RuntimeException('Native dashboard exposed editor or global diagnostics to a response-only actor.'); }
    $managedAsset->rules = '{"formstudio.submissions.view":{"2":0}}'; $managedAsset->store(); Joomla\CMS\Access\Access::clearStatics();
    $dashboardAfter = $dashboard->report((int) $visitor->id);
    if ($dashboardAfter['readable_forms'] !== $dashboardBefore['readable_forms'] - 1) { throw new RuntimeException('Native dashboard ignored form permission revocation.'); }
    echo "Native dashboard separates response-only access from editor/global diagnostics and immediately observes form ACL revocation.\n";
    try { $savedViews->read((int) $visitor->id, $savedView); throw new RuntimeException('Saved view retained revoked native form access.'); } catch (DomainException) {}
    $savedViews->remove((int) $visitor->id, $savedView);
    echo "Saved view rechecks native form ACL after revocation; owner can remove the unavailable preset.\n";
    $treeBeforeRemoval = $db->rows('SELECT id, parent_id, lft, rgt, level FROM ' . $db->quote('#__assets') . ' ORDER BY id');
    try {
        $db->transaction(function () use ($manager, $managed): void { $manager->remove($managed); throw new RuntimeException('injected_asset_delete_rollback'); });
    } catch (RuntimeException $error) { if ($error->getMessage() !== 'injected_asset_delete_rollback') { throw $error; } }
    if ($treeBeforeRemoval !== $db->rows('SELECT id, parent_id, lft, rgt, level FROM ' . $db->quote('#__assets') . ' ORDER BY id')) { throw new RuntimeException('Asset deletion escaped the surrounding transaction.'); }
    echo "Native form asset deletion rolls back all nested-set positions after a failed worker transaction.\n";
    require __DIR__ . '/joomla-export-acl.php';
    file_put_contents($root . '/build/joomla-acl-results.json', json_encode(['passed' => true, 'joomla' => JVERSION, 'timestamp' => gmdate(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
} finally { $component->rules = $originalRules; $component->store(); Joomla\CMS\Access\Access::clearStatics(); }
