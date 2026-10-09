<?php
declare(strict_types=1);
require __DIR__ . '/joomla-config.php';
$db = new Nicode\FormStudio\Infrastructure\Database\Connection($container->get(Joomla\Database\DatabaseInterface::class));
$extension = $db->row('SELECT extension_id, params FROM ' . $db->quote('#__extensions') . " WHERE type = 'component' AND element = 'com_nicode_form_studio'");
$plugin = $db->row('SELECT enabled FROM ' . $db->quote('#__extensions') . " WHERE type = 'plugin' AND folder = 'extension' AND element = 'nicode_form_studio'");
if ((int) ($plugin['enabled'] ?? 0) !== 1) { throw new RuntimeException('Installed configuration audit plugin is not enabled.'); }
$countAudit = static fn (): int => (int) $db->row('SELECT COUNT(*) AS total FROM ' . $db->table('audit_log') . " WHERE event_type = 'config.security_saved'")['total'];
$before = $countAudit();
$model = $app->bootComponent('com_config')->getMVCFactory()->createModel('Component', 'Administrator', ['ignore_request' => true]);
$model->setCurrentUser($admin);
$parameters = json_decode($extension['params'], true, 512, JSON_THROW_ON_ERROR);
try {
    $parameters['redirect_hosts'] = 'private-configuration-marker.invalid';
    if (!$model->save(['id' => (int) $extension['extension_id'], 'option' => 'com_nicode_form_studio', 'params' => $parameters])) { throw new RuntimeException('Native component configuration save failed.'); }
    if ($countAudit() !== $before + 1) { throw new RuntimeException('Native configuration save did not emit exactly one audit event.'); }
    $record = $db->row('SELECT actor_id, correlation_id, safe_metadata, form_id FROM ' . $db->table('audit_log') . " WHERE event_type = 'config.security_saved' ORDER BY id DESC LIMIT 1");
    if ((int) $record['actor_id'] !== (int) $admin->id || !Nicode\FormStudio\Domain\Uuid::valid($record['correlation_id']) || $record['safe_metadata'] !== '{}' || $record['form_id'] !== null) { throw new RuntimeException('Configuration audit identity or privacy failed.'); }
    $app->triggerEvent('onExtensionAfterSave', ['com_plugins.plugin', (object) ['type' => 'plugin', 'element' => 'nicode_form_studio'], false]);
    $app->triggerEvent('onExtensionAfterSave', ['com_config.component', (object) ['type' => 'component', 'element' => 'com_content'], false]);
    if ($countAudit() !== $before + 1) { throw new RuntimeException('Unrelated native saves contaminated FormStudio audit.'); }
    try { $model->save(['id' => PHP_INT_MAX, 'option' => 'com_nicode_form_studio', 'params' => []]); throw new LogicException('Invalid extension save accepted.'); }
    catch (RuntimeException) {}
    if ($countAudit() !== $before + 1) { throw new RuntimeException('Failed configuration save emitted audit success.'); }
} finally {
    $db->execute('UPDATE ' . $db->quote('#__extensions') . ' SET params = :params WHERE extension_id = :id', [':params' => $extension['params'], ':id' => (int) $extension['extension_id']]);
}
echo "Native configuration audit: installed plugin, successful model save, actor/correlation, private parameters excluded, unrelated/failed saves excluded.\n";
