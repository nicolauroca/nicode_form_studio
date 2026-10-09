<?php
declare(strict_types=1);

// This suite runs only through the guarded, isolated database.php entry point.
$connection->execute('CREATE TABLE IF NOT EXISTS ' . $connection->quote('#__assets') . ' (id BIGINT PRIMARY KEY, name VARCHAR(255) NOT NULL)');
$dashboardNow = strtotime('2031-05-16 12:00:00 UTC'); $dashboardActor = random_int(8000000, 9000000);
$dashboardForms = [];
foreach (['managed', 'reader', 'hidden'] as $kind) {
    $dashboardForm = $forms->create('Dashboard ' . $kind, 'dashboard-' . bin2hex(random_bytes(5)), $dashboardActor);
    $dashboardDraft = $forms->draft($dashboardForm); $dashboardField = Nicode\FormStudio\Domain\Uuid::create();
    $dashboardDraft['elements'] = [['uuid' => $dashboardField, 'type' => 'field', 'parent_uuid' => null]];
    $dashboardDraft['fields'] = [['uuid' => $dashboardField, 'type' => 'text', 'name' => 'secret', 'config' => []]];
    $dashboardRevision = $forms->saveDraft($dashboardForm, 0, $dashboardDraft, $dashboardActor);
    $dashboardVersion = $forms->publish($dashboardForm, $dashboardRevision, $dashboardActor);
    $dashboardForms[$kind] = ['id' => $dashboardForm, 'version' => $dashboardVersion, 'spec' => $forms->version($dashboardForm, $dashboardVersion), 'field' => $dashboardField];
}
$dashboardResponseIds = [];
foreach (range(0, 13) as $day) {
    $fixture = $dashboardForms['reader'];
    $response = $submissions->persist($fixture['id'], $fixture['version'], $fixture['spec'], [$fixture['field'] => 'never-disclose-dashboard-secret'], hash('sha256', random_bytes(32)));
    $dashboardResponseIds[] = $response->id;
    $age = $day === 12 ? 31 : ($day === 13 ? -1 : $day);
    $connection->execute('UPDATE ' . $connection->table('submissions') . ' SET received_at = :date, index_pending = :pending, state = :state, action_status = :status WHERE id = :id', [':date' => gmdate('Y-m-d H:i:s', $dashboardNow - $age * 86400), ':pending' => $day === 0 ? 1 : 0, ':state' => $day === 0 ? 'spam' : 'new', ':status' => $day === 1 ? 'partial_failure' : ($day === 2 ? 'blocking_failure' : 'succeeded'), ':id' => $response->id]);
}
foreach (['managed', 'hidden'] as $kind) {
    $fixture = $dashboardForms[$kind];
    $submissions->persist($fixture['id'], $fixture['version'], $fixture['spec'], [$fixture['field'] => 'never-disclose-dashboard-secret'], hash('sha256', random_bytes(32)));
}
foreach (['email_notification', 'webhook', 'email_autoresponse'] as $i => $type) {
    $connection->insert('action_runs', ['submission_id' => $dashboardResponseIds[0], 'action_uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'action_type' => $type, 'attempt' => 1, 'state' => $i < 2 ? 'failed' : 'succeeded', 'created_at' => gmdate('Y-m-d H:i:s', $dashboardNow), 'revision' => 0]);
}
$connection->insert('submission_files', ['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'submission_id' => $dashboardResponseIds[0], 'field_uuid' => $dashboardForms['reader']['field'], 'provider' => 'test', 'storage_key' => bin2hex(random_bytes(32)), 'original_name' => 'never-disclose-dashboard-filename.txt', 'mime' => 'text/plain', 'size_bytes' => 123, 'checksum' => str_repeat('a', 64), 'created_at' => gmdate('Y-m-d H:i:s', $dashboardNow)]);
$dashboardOwnJob = $jobs->enqueue('export-csv', ['form_id' => $dashboardForms['reader']['id']], $dashboardActor);
$dashboardOtherJob = $jobs->enqueue('file-cleanup', ['objects' => []], $dashboardActor + 1);
$dashboardStalledJob = $jobs->enqueue('test-dashboard-stalled', [], $dashboardActor);
$connection->execute('UPDATE ' . $connection->table('jobs') . " SET state = 'running', lease_until = :expired WHERE id = :id", [':expired' => gmdate('Y-m-d H:i:s', $dashboardNow - 1), ':id' => $dashboardStalledJob]);
$dashboardGlobal = false; $dashboardRevoked = false; $dashboardInspected = [];
$dashboardAuthorize = static fn (int $actor, ?int $form, string $permission): bool => $actor === $dashboardActor && $permission === 'core.manage';
$dashboardManage = static function (int $actor, array $rows) use ($dashboardActor, $dashboardForms, &$dashboardRevoked): array {
    if ($actor !== $dashboardActor || $dashboardRevoked) { return []; }
    $out = []; foreach ($rows as $row) { if ((int) $row['id'] === $dashboardForms['managed']['id']) { $out[(int) $row['id']] = ['core.edit' => true]; } } return $out;
};
$dashboardRead = static function (int $actor, array $rows) use ($dashboardActor, $dashboardForms, &$dashboardRevoked): array {
    if ($actor !== $dashboardActor || $dashboardRevoked) { return []; }
    $out = []; foreach ($rows as $row) { if ((int) $row['id'] === $dashboardForms['reader']['id']) { $out[(int) $row['id']] = []; } } return $out;
};
$dashboard = new Nicode\FormStudio\Application\OperationsDashboard($connection, $dashboardAuthorize, $dashboardManage, $dashboardRead, static function (int $id, ?int $version) use (&$dashboardInspected): array { $dashboardInspected[] = [$id, $version]; return ['invalid' => true, 'captcha' => true]; }, static fn (): int => $dashboardNow);
try { $dashboardReport = $dashboard->report($dashboardActor); }
finally { $jobs->cancel($dashboardOwnJob); $jobs->cancel($dashboardOtherJob); $jobs->cancel($dashboardStalledJob); }
foreach (['forms_published' => 1, 'responses_7' => 8, 'responses_30' => 12, 'spam' => 1, 'response_errors' => 2, 'actions_failed' => 2, 'emails_failed' => 1, 'webhooks_failed' => 1, 'storage_bytes' => 123, 'index_pending' => 1, 'forms_invalid' => 1, 'forms_captcha' => 1] as $key => $expected) {
    if ($dashboardReport['metrics'][$key] !== $expected) { throw new RuntimeException('Dashboard aggregate mismatch: ' . $key); }
}
if (count($dashboardReport['recent']) !== 10 || $dashboardReport['recent'][0]['id'] != $dashboardResponseIds[13] || array_unique(array_column($dashboardReport['recent'], 'form_id')) != [$dashboardForms['reader']['id']] || count($dashboardReport['modified']) !== 1 || $dashboardReport['jobs']['pending'] !== 1 || $dashboardReport['jobs']['exports'] !== 1 || $dashboardReport['jobs']['cleanup'] !== 0 || $dashboardReport['technical_errors'] !== null || $dashboardReport['disabled_sources'] !== null || $dashboardInspected !== [[$dashboardForms['managed']['id'], $dashboardForms['managed']['version']]]) { throw new RuntimeException('Dashboard list, job or diagnostic ACL scope failed.'); }
if (str_contains(json_encode($dashboardReport, JSON_THROW_ON_ERROR), 'never-disclose-dashboard') || str_contains(json_encode($dashboardReport, JSON_THROW_ON_ERROR), 'Dashboard hidden')) { throw new RuntimeException('Dashboard exposed private content.'); }
if ($dashboardReport['jobs']['stalled'] !== 1 || $dashboardReport['jobs']['running'] !== 1) { throw new RuntimeException('Stalled worker lease was not reported.'); }
try { $dashboard->report(0); throw new RuntimeException('Dashboard access bypass.'); } catch (DomainException) {}
$dashboardRevoked = true;
$revokedReport = $dashboard->report($dashboardActor);
if (array_sum($revokedReport['metrics']) !== 0 || $revokedReport['recent'] !== [] || $revokedReport['modified'] !== []) { throw new RuntimeException('Dashboard ignored permission revocation.'); }
echo "Dashboard UTC windows, metadata aggregates, action/file counts, bounded recent lists, separate form/response ACL, own jobs and revocation verified.\n";

$dashboardSecrets = new class implements Nicode\FormStudio\Contract\SecretStoreInterface { public function get(string $name): string { throw new DomainException('private-diagnostic-secret'); } };
$dashboardDependencies = new Nicode\FormStudio\Registry\ProviderDependencies(['fields' => $registry, 'actions' => new Nicode\FormStudio\Registry\ProviderRegistry(), 'sources' => new Nicode\FormStudio\Registry\ProviderRegistry(), 'validators' => new Nicode\FormStudio\Registry\ProviderRegistry(), 'operators' => Nicode\FormStudio\Registry\RuleOperatorRegistry::core(), 'effects' => Nicode\FormStudio\Registry\RuleEffectRegistry::core()]);
$dashboardReadiness = new Nicode\FormStudio\Application\PublicationReadiness(new Nicode\FormStudio\Registry\StorageProviderRegistry(), $dashboardSecrets, static fn (): bool => false);
$dashboardDiagnostics = new Nicode\FormStudio\Application\FormDiagnostics($forms, $compiler, $adminCaptcha, $dashboardReadiness, $dashboardDependencies);
$fixture = $dashboardForms['managed']; $adminCaptcha->available = true;
if (array_filter($dashboardDiagnostics->inspect($fixture['id'], $fixture['version'])) !== []) { throw new RuntimeException('Healthy definition reported a diagnostic.'); }
$adminCaptcha->available = false;
if (!$dashboardDiagnostics->inspect($fixture['id'], $fixture['version'])['captcha']) { throw new RuntimeException('Unavailable CAPTCHA not detected.'); }
$adminCaptcha->available = true;
$dashboardDraft = $forms->draft($fixture['id']);
$dashboardDraft['actions'] = [['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'type' => 'missing-diagnostic-provider', 'enabled' => true, 'config' => []]];
$forms->saveDraft($fixture['id'], (int) $forms->get($fixture['id'])['draft_revision'], $dashboardDraft, $dashboardActor);
$diagnostic = $dashboardDiagnostics->inspect($fixture['id'], $fixture['version']);
if (!$diagnostic['invalid'] || !$diagnostic['action'] || !$diagnostic['provider'] || $diagnostic['unavailable']) { throw new RuntimeException('Incomplete action diagnostics failed.'); }
$connection->execute('UPDATE ' . $connection->table('forms') . ' SET params = :params WHERE id = :id', [':params' => '{private-diagnostic-secret', ':id' => $fixture['id']]);
$diagnostic = $dashboardDiagnostics->inspect($fixture['id'], $fixture['version']);
if (!$diagnostic['unavailable'] || str_contains(json_encode($diagnostic, JSON_THROW_ON_ERROR), 'private-diagnostic-secret')) { throw new RuntimeException('Unavailable diagnosis appeared healthy or disclosed internal text.'); }
// Restore the fixture after proving malformed data is safely classified.
$connection->execute('UPDATE ' . $connection->table('forms') . ' SET params = :params WHERE id = :id', [':params' => '{"schema_version":"1.0"}', ':id' => $fixture['id']]);
echo "Live definition diagnostics: healthy snapshot, unavailable CAPTCHA, missing action/provider and safe malformed-definition failure passed.\n";

$dashboardRevoked = false; $dashboardEvents = [];
for ($i = 0; $i < 3; $i++) { $dashboardEvents[] = $connection->insert('technical_log', ['correlation_id' => Nicode\FormStudio\Domain\Uuid::create(), 'level' => 'ERROR', 'event_type' => 'storage.unavailable', 'created_at' => gmdate('Y-m-d H:i:s', $dashboardNow - 60)]); }
try {
    $dashboardAdmin = new Nicode\FormStudio\Application\OperationsDashboard($connection, static fn (int $actor): bool => $actor === $dashboardActor, $dashboardManage, $dashboardRead, static fn (): array => [], static fn (): int => $dashboardNow, static fn (): array => ['storage_path' => ['status' => 'low_space', 'detail' => 'never-disclose-health-path'], 'mail' => ['status' => 'unexpected-private-value'], 'cache' => ['status' => 'ok'], 'untrusted_key' => ['status' => 'warning']]);
    $globalReport = $dashboardAdmin->report($dashboardActor);
    if ($globalReport['technical_errors'] < 3 || !in_array('storage.unavailable', array_column($globalReport['repeated_errors'], 'event_type'), true) || $globalReport['health_alerts'] !== ['storage_path' => 'low_space', 'mail' => 'unavailable'] || str_contains(json_encode($globalReport, JSON_THROW_ON_ERROR), 'never-disclose-health-path')) { throw new RuntimeException('Operational alert aggregation or private health detail boundary failed.'); }
    $dashboardLimited = new Nicode\FormStudio\Application\OperationsDashboard($connection, $dashboardAuthorize, $dashboardManage, $dashboardRead, static fn (): array => [], static fn (): int => $dashboardNow, static function (): array { throw new LogicException('No log permission must never probe global health.'); });
    $limitedReport = $dashboardLimited->report($dashboardActor);
    if ($limitedReport['health_alerts'] !== [] || $limitedReport['repeated_errors'] !== []) { throw new RuntimeException('Operational alerts ignored global logs ACL.'); }
} finally {
    foreach ($dashboardEvents as $event) { $connection->execute('DELETE FROM ' . $connection->table('technical_log') . ' WHERE id = :id', [':id' => $event]); }
}
echo "Dashboard stalled leases, repeated-error threshold, health allowlist, private-detail suppression and global log ACL passed.\n";
