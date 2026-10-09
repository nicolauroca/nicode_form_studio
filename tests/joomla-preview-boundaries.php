<?php
declare(strict_types=1);
require __DIR__ . '/joomla-option-defaults.php';
$preview = $runtime->get(Nicode\FormStudio\Application\FormPreview::class);
$db = $runtime->get(Nicode\FormStudio\Infrastructure\Database\Connection::class);
$revision = (int) $administration->edit($form, (int) $admin->id)['form']['draft_revision'];
$snapshot = static function () use ($db, $form): array {
    $result = ['form' => $db->row('SELECT * FROM ' . $db->table('forms') . ' WHERE id = :id', [':id' => $form])];
    foreach (['form_versions', 'submissions', 'audit_log'] as $table) { $result[$table] = $db->rows('SELECT * FROM ' . $db->table($table) . ' WHERE form_id = :id ORDER BY id', [':id' => $form]); }
    $result['actions'] = $db->rows('SELECT a.* FROM ' . $db->table('action_runs') . ' a JOIN ' . $db->table('submissions') . ' s ON s.id = a.submission_id WHERE s.form_id = :id', [':id' => $form]);
    return $result;
};
$before = $snapshot();
$database = $container->get(Joomla\Database\DatabaseInterface::class);
$events = $container->get(Joomla\Event\DispatcherInterface::class);
$asset = new Joomla\CMS\Table\Asset($database, $events);
if (!$asset->loadByName('com_nicode_form_studio.form.' . $form)) { throw new RuntimeException('Preview fixture asset missing.'); }
$original = $asset->rules;
$component = new Joomla\CMS\Table\Asset($database, $events);
if (!$component->loadByName('com_nicode_form_studio')) { throw new RuntimeException('Component asset missing.'); }
$originalComponent = $component->rules;
$visitor = new Joomla\CMS\User\User(); $suffix = bin2hex(random_bytes(6)); $password = bin2hex(random_bytes(24));
$visitorData = ['name' => 'Preview ACL fixture', 'username' => 'preview-acl-' . $suffix, 'email' => 'preview-' . $suffix . '@example.test', 'password' => $password, 'password2' => $password, 'groups' => [2], 'block' => 0];
if (!$visitor->bind($visitorData) || !$visitor->save()) { throw new RuntimeException('Preview ACL user preparation failed.'); }
unset($password, $visitorData);
$deny = static function (int $actor) use ($preview, $form, $revision, $parent): void {
    try { $preview->options($form, $actor, $revision, [$parent => 'ES']); }
    catch (DomainException) { return; }
    throw new RuntimeException('Preview options bypassed form edit permission.');
};
try {
    $deny(0); $deny((int) $visitor->id);
    $componentRules = json_decode($originalComponent ?: '{}', true, 32, JSON_THROW_ON_ERROR);
    $componentRules['core.manage']['2'] = 1;
    $component->rules = json_encode($componentRules, JSON_THROW_ON_ERROR); $component->store();
    $asset->rules = '{"core.edit":{"2":1},"formstudio.forms.manage":{"2":1}}'; $asset->store(); Joomla\CMS\Access\Access::clearStatics();
    $allowed = $preview->options($form, (int) $visitor->id, $revision, [$parent => 'ES'], ['language' => 'en-GB', 'view_levels' => [1]]);
    if (!isset($allowed['options'])) { throw new RuntimeException('Authorized preview options failed.'); }
    if ($remote && (count($allowed['options']) !== 2 || array_column(reset($allowed['options']), 'value') !== ['MD', 'BC'])) { throw new RuntimeException('Authorized remote source did not resolve expected options.'); }
    $asset->rules = '{"core.edit":{"2":0},"formstudio.forms.manage":{"2":1}}'; $asset->store(); Joomla\CMS\Access\Access::clearStatics();
    $deny((int) $visitor->id);
    $preview->options($form, (int) $admin->id, $revision, [$parent => 'FR']);
    if ($snapshot() !== $before) { throw new RuntimeException('Option preview changed form, versions, submissions, actions or audit rows.'); }
} finally {
    $asset->rules = $original; $asset->store(); Joomla\CMS\Access\Access::clearStatics();
    $component->rules = $originalComponent; $component->store(); Joomla\CMS\Access\Access::clearStatics();
}
file_put_contents(__DIR__ . '/../build/joomla-preview-boundaries-results.json', json_encode(['passed' => true, 'timestamp' => gmdate(DATE_ATOM), 'form_id' => $form, 'remote' => $remote, 'checks' => ['guest denied', 'form edit permission required', 'explicit grant accepted', 'revocation immediate', 'form versions submissions actions audit unchanged']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Native preview boundaries: guest/form denial, explicit edit grant, immediate revocation and unchanged form/version/submission/action/audit state passed.\n";
