<?php
declare(strict_types=1);

$adminCaptcha = new class implements Nicode\FormStudio\Contract\CaptchaAdapterInterface {
    public bool $available = true;
    public function available(): array { return []; }
    public function assertAvailable(Nicode\FormStudio\Security\CaptchaPolicy $policy): void { if (!$this->available) { throw new Nicode\FormStudio\Security\CaptchaException('captcha_unavailable'); } }
    public function render(Nicode\FormStudio\Security\CaptchaPolicy $policy, string $instance): string { return ''; }
    public function validate(Nicode\FormStudio\Security\CaptchaPolicy $policy, ?string $answer): void {}
};
$adminDenied = []; $adminAttachFail = false;
$adminAuthorize = static function (int $actor, ?int $form, string $permission) use (&$adminDenied): bool { return $actor === 731 && !in_array($permission, $adminDenied, true); };
$adminAttach = static function (int $form) use (&$adminAttachFail): void { if ($adminAttachFail) { throw new RuntimeException('Simulated asset failure.'); } };
$formAdministration = new Nicode\FormStudio\Application\FormAdministration($forms, $connection, $adminAuthorize, $adminAttach, $adminCaptcha);
$adminAlias = 'admin-' . bin2hex(random_bytes(5));
$adminForm = $formAdministration->create('Administrative fixture', $adminAlias, 731);
$adminDraft = $formAdministration->edit($adminForm, 731)['draft'];
$adminField = Nicode\FormStudio\Domain\Uuid::create();
$adminDraft['elements'] = [['uuid' => $adminField, 'type' => 'field', 'parent_uuid' => null]];
$adminDraft['fields'] = [['uuid' => $adminField, 'type' => 'text', 'name' => 'answer', 'config' => ['label' => 'Original']]];
$adminRevision = $formAdministration->save($adminForm, 0, $adminDraft, 731);
$adminVersion = $formAdministration->publish($adminForm, $adminRevision, 731);
$adminRevision = (int) $forms->get($adminForm)['draft_revision'];
$adminSnapshot = $forms->version($adminForm, $adminVersion)->hash;
$adminDenied = ['formstudio.forms.publish'];
try { $formAdministration->deactivate($adminForm, $adminRevision, 731); throw new RuntimeException('Publication permission bypass.'); } catch (DomainException) {}
$adminDenied = ['core.edit'];
try { $formAdministration->edit($adminForm, 731); throw new RuntimeException('Editor permission bypass.'); } catch (DomainException) {}
$adminDenied = [];
$adminCaptcha->available = false;
try { $formAdministration->publish($adminForm, $adminRevision, 731); throw new RuntimeException('Unavailable CAPTCHA was published.'); } catch (Nicode\FormStudio\Security\CaptchaException) {}
if ((int) $forms->get($adminForm)['published_version_id'] !== $adminVersion || count($formAdministration->history($adminForm, 731)) !== 1) { throw new RuntimeException('Failed publication changed history.'); }
$adminCaptcha->available = true;
$adminAttachFail = true;
$adminDraft['name'] = 'Should roll back';
try { $formAdministration->save($adminForm, $adminRevision, $adminDraft, 731); throw new LogicException('Asset failure was ignored.'); } catch (RuntimeException $error) { if ($error->getMessage() !== 'Simulated asset failure.') { throw $error; } }
if ($forms->get($adminForm)['name'] !== 'Administrative fixture' || (int) $forms->get($adminForm)['draft_revision'] !== $adminRevision) { throw new RuntimeException('Nested draft save committed before asset failure.'); }
try { $formAdministration->create('Should roll back', $adminAlias . '-failure', 731); throw new LogicException('Asset failure was ignored.'); } catch (RuntimeException $error) { if ($error->getMessage() !== 'Simulated asset failure.') { throw $error; } }
if ($connection->row('SELECT id FROM ' . $connection->table('forms') . ' WHERE alias = :alias', [':alias' => $adminAlias . '-failure']) !== null) { throw new RuntimeException('Failed creation left an orphan form.'); }
$adminAttachFail = false;
$adminRevision = $formAdministration->deactivate($adminForm, $adminRevision, 731);
if ($forms->get($adminForm)['state'] !== 'unpublished' || $forms->version($adminForm, $adminVersion)->hash !== $adminSnapshot) { throw new RuntimeException('Deactivation damaged historical snapshot.'); }
try { $formAdministration->deactivate($adminForm, $adminRevision - 1, 731); throw new RuntimeException('Stale state change accepted.'); } catch (Nicode\FormStudio\Domain\ConcurrentEdit) {}
$formAdministration->restore($adminForm, $adminVersion, $adminRevision, 731);
if ($forms->get($adminForm)['state'] !== 'unpublished' || (int) $forms->get($adminForm)['published_version_id'] !== $adminVersion) { throw new RuntimeException('Restore activated an unpublished form.'); }
if (count($connection->rows('SELECT id FROM ' . $connection->table('audit_log') . ' WHERE form_id = :form', [':form' => $adminForm])) !== 5) { throw new RuntimeException('Administrative audit is missing or contains failed operations.'); }
$adminAudit = $connection->rows('SELECT actor_id, event_type, correlation_id FROM ' . $connection->table('audit_log') . ' WHERE form_id = :form ORDER BY id', [':form' => $adminForm]);
if (array_column($adminAudit, 'event_type') !== ['form.create', 'form.save', 'form.publish', 'form.deactivate', 'form.restore'] || array_unique(array_map('intval', array_column($adminAudit, 'actor_id'))) !== [731]) { throw new RuntimeException('Form audit lost event order or actor identity.'); }
foreach ($adminAudit as $entry) { if (!Nicode\FormStudio\Domain\Uuid::valid($entry['correlation_id'])) { throw new RuntimeException('Form audit correlation invalid.'); } }
echo "Form administration ACL, publication CAPTCHA, transactional assets, nested rollback, immutable deactivation and restore verified.\n";
$adminRevision = (int) $forms->get($adminForm)['draft_revision'];
$adminSettings = ['name' => 'Updated title', 'alias' => $adminAlias, 'access' => 2, 'language' => 'es-ES', 'publish_up' => '2026-09-01 12:00:00', 'publish_down' => '2026-10-01 12:00:00'];
$adminDenied = ['core.edit.state'];
try { $formAdministration->settings($adminForm, $adminRevision, $adminSettings, 731); throw new RuntimeException('Publication settings permission bypass.'); } catch (DomainException) {}
$adminDenied = [];
foreach ([['publish_down' => '2026-08-01 12:00:00'], ['publish_up' => '2026-02-30 12:00:00'], ['access' => 0], ['language' => '../../en'], ['unexpected' => true], ['name' => str_repeat('a', 256)]] as $invalidSettings) {
    try { $formAdministration->settings($adminForm, $adminRevision, array_replace($adminSettings, $invalidSettings), 731); throw new RuntimeException('Invalid form settings accepted.'); } catch (InvalidArgumentException) {}
}
$adminRevision = $formAdministration->settings($adminForm, $adminRevision, $adminSettings, 731);
if ($forms->get($adminForm)['name'] !== 'Updated title' || (int) $forms->get($adminForm)['access'] !== 2 || $forms->version($adminForm, $adminVersion)->hash !== $adminSnapshot) { throw new RuntimeException('Settings did not update metadata independently of history.'); }
try { $formAdministration->save($adminForm, $adminRevision - 1, $adminDraft, 731); throw new RuntimeException('Settings did not invalidate stale editor.'); } catch (Nicode\FormStudio\Domain\ConcurrentEdit) {}
echo "Form access/language/schedule controls, exact calendar validation and shared edit revision verified.\n";
require __DIR__ . '/database-form-selection.php';
