<?php
declare(strict_types=1);

$templates = new Nicode\FormStudio\Application\Templates($connection, $formAdministration, $transfer, $adminAuthorize);
$templateMailId = $templates->createEmail(731, 'Reusable email');
$templateEmail = ['subject' => '{{form.name}} received', 'body_text' => '{{response.summary}}', 'body_html' => ''];
$templates->saveEmail(731, $templateMailId, 0, 'Reusable email', 'es-ES', $templateEmail);
try { $templates->saveEmail(731, $templateMailId, 0, 'Stale edit', 'es-ES', $templateEmail); throw new LogicException('Template accepted a stale write.'); } catch (Nicode\FormStudio\Domain\ConcurrentEdit) {}
$templateConfig = $templates->emailConfiguration(731, $templateMailId, 1, $adminForm, []);
if ($templateConfig['language'] !== 'es-ES' || $templateConfig['config']['template']['revision'] !== 1 || isset($templateConfig['config']['body_html'])) { throw new RuntimeException('Reusable email lost pinned configuration or enabled empty HTML.'); }
$templates->saveEmail(731, $templateMailId, 1, 'Changed email', 'en-GB', ['subject' => 'Changed later', 'body_text' => 'Changed']);
if ($templateConfig['config']['subject'] !== '{{form.name}} received') { throw new RuntimeException('Resource edit mutated an applied copy.'); }
try { $templates->emailConfiguration(731, $templateMailId, 1, $adminForm, []); throw new LogicException('Template application silently used a changed revision.'); } catch (Nicode\FormStudio\Domain\ConcurrentEdit) {}
$sourceRevision = (int) $forms->get($adminForm)['draft_revision'];
$captured = $templates->captureForm(731, 'Reusable form', $adminForm, $sourceRevision);
$resourceRecord = $templates->read(731, 'form', $captured['id']);
if ($resourceRecord['revision'] !== 1 || $resourceRecord['package']['mode'] !== 'portable') { throw new RuntimeException('Form template did not preserve a portable definition.'); }
$templateAlias = 'template-' . bin2hex(random_bytes(6));
$templatePreview = $templates->previewForm(731, $captured['id'], 1, 'Created from template', $templateAlias);
$templateCreated = $templates->createForm(731, $captured['id'], 1, 'Created from template', $templateAlias, $templatePreview['review_token'], true);
$templateForm = $forms->get($templateCreated['id']);
if ($templateForm['state'] !== 'draft' || $templateForm['published_version_id'] !== null || $templateForm['uuid'] === $forms->get($adminForm)['uuid']) { throw new RuntimeException('Template creation reused identity or published without review.'); }
$templates->captureForm(731, 'Updated resource', $adminForm, $sourceRevision, $captured['id'], 1);
try { $templates->previewForm(731, $captured['id'], 1, 'Stale resource', 'stale-template-' . bin2hex(random_bytes(4))); throw new LogicException('Template preview accepted stale revision.'); } catch (Nicode\FormStudio\Domain\ConcurrentEdit) {}
$adminDenied = ['formstudio.resources.manage'];
try { $templates->createEmail(731, 'Denied'); throw new LogicException('Resource mutation bypassed ACL.'); } catch (DomainException) {}
$templates->listing(731, 'email');
$adminDenied = ['core.edit'];
try { $templates->captureForm(731, 'Denied capture', $adminForm, $sourceRevision); throw new LogicException('Template capture bypassed form edit ACL.'); } catch (DomainException) {}
$adminDenied = [];
echo "Reusable templates: optimistic revisions, copied email configuration, stale apply rejection, portable form capture, signed draft creation and independent resource/form ACL passed.\n";
