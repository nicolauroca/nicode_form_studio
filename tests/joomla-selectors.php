<?php
declare(strict_types=1);
require __DIR__ . '/joomla-config.php';
$app->loadDocument();
$runtime = $app->bootComponent('com_nicode_form_studio')->runtime($app);
$listing = $runtime->get(Nicode\FormStudio\Application\FormListing::class)->page((int) $admin->id, ['state' => 'published']);
$candidate = $listing['rows'][0] ?? throw new RuntimeException('Published selector fixture missing.');
$menuForm = Joomla\CMS\Form\Form::getInstance('formstudio.menu.selector.test', $site . '/components/com_nicode_form_studio/tmpl/form/default.xml', [], false, '/metadata');
$menuField = $menuForm->getField('id', 'request', (int) $candidate['id']);
if (!$menuField instanceof Nicode\Component\FormStudio\Administrator\Field\FormStudioField) { throw new RuntimeException('Native menu did not load the namespaced selector.'); }
$menuInput = $menuField->input;
if (!str_contains($menuInput, htmlspecialchars($candidate['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) || !str_contains($menuInput, 'value="' . (int) $candidate['id'] . '"')) { throw new RuntimeException('Menu selector omitted an authorized published form.'); }
$moduleForm = Joomla\CMS\Form\Form::getInstance('formstudio.module.selector.test', $site . '/modules/mod_nicode_form_studio/mod_nicode_form_studio.xml', [], false, '/extension/config');
$moduleField = $moduleForm->getField('form_id', 'params', (int) $candidate['id']);
if (!$moduleField instanceof Nicode\Component\FormStudio\Administrator\Field\FormStudioField || !str_contains($moduleField->input, 'value="' . (int) $candidate['id'] . '"')) { throw new RuntimeException('Native module selector missing.'); }
$app->loadIdentity(new Joomla\CMS\User\User());
$deniedForm = Joomla\CMS\Form\Form::getInstance('formstudio.denied.selector.test', $site . '/modules/mod_nicode_form_studio/mod_nicode_form_studio.xml', [], false, '/extension/config');
$deniedField = $deniedForm->getField('form_id', 'params', (int) $candidate['id']);
$deniedInput = $deniedField->input;
file_put_contents($root . '/build/denied-selector.html', $deniedInput);
if (str_contains($deniedInput, htmlspecialchars($candidate['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) || !str_contains($deniedInput, 'Unavailable form')) { throw new RuntimeException('Selector disclosed a title outside ACL scope or discarded the stored ID.'); }
echo "Native menu/module form selectors: namespaced loading, published choices, stored selection and denied-title masking verified.\n";
