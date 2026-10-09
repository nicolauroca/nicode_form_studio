<?php
declare(strict_types=1);

$displayForm = $forms->create('Published display', 'display-' . bin2hex(random_bytes(5)), 1);
$displayDraft = $forms->draft($displayForm); $displayField = Nicode\FormStudio\Domain\Uuid::create();
$displayDraft['elements'] = [['uuid' => $displayField, 'type' => 'field', 'parent_uuid' => null]];
$displayDraft['fields'] = [['uuid' => $displayField, 'type' => 'text', 'name' => 'answer', 'config' => ['label' => 'Published label', 'default' => 'Published default']]];
$displayRevision = $forms->saveDraft($displayForm, 0, $displayDraft, 1); $displayVersion = $forms->publish($displayForm, $displayRevision, 1);
$displayDraft['name'] = 'Private draft'; $displayDraft['fields'][0]['config']['label'] = 'Private draft label';
$forms->saveDraft($displayForm, (int) $forms->get($displayForm)['draft_revision'], $displayDraft, 1);
$displayRenderers = new Nicode\FormStudio\Rendering\FieldRendererRegistry(); $displayRenderers->register('text', new Nicode\FormStudio\Rendering\CoreFieldRenderer());
$displayRenderer = new Nicode\FormStudio\Rendering\FormRenderer($displayRenderers, new Nicode\FormStudio\Rendering\PublicSpec($registry));
$display = new Nicode\FormStudio\Application\FormDisplay($forms, $registry, $ruleEngine, $displayRenderer, new Nicode\FormStudio\Security\PublicAccess(), $attemptTokens, $captchaFixture);
$displayComponentContext = new Nicode\FormStudio\Submission\RequestContext(0, [1], 'en-GB', 'display-session', hash('sha256', 'display-test'), true);
$displayModuleContext = new Nicode\FormStudio\Submission\RequestContext(0, [1], 'en-GB', 'display-session', hash('sha256', 'display-test'), true, 'module');
$componentHtml = $display->render($displayForm, $displayComponentContext, 'component-test', '/index.php', 'csrf_fixture');
$moduleHtml = $display->render($displayForm, $displayModuleContext, 'module-test', '/index.php', 'csrf_fixture');
if ($componentHtml['version_id'] !== $displayVersion || $moduleHtml['title'] !== 'Published display' || str_contains($componentHtml['html'], 'Private draft') || !str_contains($moduleHtml['html'], 'value="Published default"') || !str_contains($moduleHtml['html'], 'name="channel" value="module"')) { throw new RuntimeException('Shared display exposed draft or lost default/channel.'); }
preg_match('/name="attempt" value="([^"]+)"/', $componentHtml['html'], $componentToken);
preg_match('/name="attempt" value="([^"]+)"/', $moduleHtml['html'], $moduleToken);
$attemptTokens->verify($componentToken[1], $displayForm, $displayVersion, 'display-session:component');
$attemptTokens->verify($moduleToken[1], $displayForm, $displayVersion, 'display-session:module');
$retryHtml = $display->render($displayForm, $displayComponentContext, 'retry-test', '/index.php', 'csrf_fixture', submitted: [$displayField => 'Retried answer'], attempt: $componentToken[1]);
if (!str_contains($retryHtml['html'], 'name="attempt" value="' . $componentToken[1] . '"') || !str_contains($retryHtml['html'], 'Retried answer')) { throw new RuntimeException('Redisplay replaced the submission attempt or values.'); }
try { $display->render($displayForm, $displayComponentContext, 'forged-retry', '/index.php', 'csrf_fixture', attempt: $moduleToken[1]); throw new RuntimeException('Redisplay accepted a token from another channel.'); } catch (DomainException) {}
try { $attemptTokens->verify($moduleToken[1], $displayForm, $displayVersion, 'display-session:component'); throw new RuntimeException('Module attempt accepted on component channel.'); } catch (DomainException) {}
$hiddenVisitor = new Nicode\FormStudio\Submission\RequestContext(0, [2], 'en-GB', 'display-session', hash('sha256', 'display-test'), true);
try { $display->render($displayForm, $hiddenVisitor, 'denied', '/index.php', 'csrf_fixture'); throw new RuntimeException('Display bypassed view level.'); } catch (OutOfBoundsException) {}
$forms->deactivate($displayForm, (int) $forms->get($displayForm)['draft_revision'], 1, 'unpublished');
try { $display->render($displayForm, $displayModuleContext, 'denied', '/index.php', 'csrf_fixture'); throw new RuntimeException('Module rendered an unpublished form.'); } catch (OutOfBoundsException) {}
echo "Shared published display: draft isolation, defaults, access, deactivation and session/channel-bound tokens verified.\n";
