<?php
declare(strict_types=1);
defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
$escape = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$data = $this->data; $id = (int) $data['resource']['id'];
?>
<section class="nfs-admin" data-nfs-resources data-nfs-optionset data-csrf="<?= $escape($data['csrf']) ?>">
  <a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=resources', false)) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_OPTION_SETS') ?></a>
  <h1><?= $escape($data['snapshot']['name']) ?></h1>
  <p><?= Text::sprintf('COM_NICODE_FORM_STUDIO_RESOURCE_REVISION_HELP', (int) $data['snapshot']['revision'], (int) $data['resource']['revision']) ?></p>
  <p><?= Text::_('COM_NICODE_FORM_STUDIO_OPTION_SETS_HELP') ?></p>
  <p role="status" aria-live="polite" data-nfs-resource-status></p>
  <form data-nfs-resource-edit><fieldset <?= $data['can_edit'] ? '' : 'disabled' ?>>
    <legend><?= Text::_('COM_NICODE_FORM_STUDIO_OPTIONS') ?></legend>
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_NAME') ?><input name="name" required maxlength="255" value="<?= $escape($data['snapshot']['name']) ?>"></label>
    <div data-nfs-option-rows></div>
    <?php if ($data['can_edit']): ?><button type="button" class="btn btn-secondary" data-nfs-option-add><?= Text::_('COM_NICODE_FORM_STUDIO_ADD_OPTION') ?></button>
    <button type="submit" class="btn btn-primary"><?= Text::_('COM_NICODE_FORM_STUDIO_RESOURCE_SAVE') ?></button><?php endif ?>
  </fieldset></form>
  <h2><?= Text::_('COM_NICODE_FORM_STUDIO_VERSIONS') ?></h2>
  <a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=optionset&id=' . $id, false)) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_RESOURCE_CURRENT') ?></a>
  <ul><?php foreach ($data['history']['rows'] as $version): ?><li><a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=optionset&id=' . $id . '&revision=' . (int) $version['revision'], false)) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_RESOURCE_REVISION') ?> <?= (int) $version['revision'] ?></a> · <?= $escape($version['created_at']) ?> UTC</li><?php endforeach ?></ul>
  <?php if ($data['history']['next_before'] !== null): ?><a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=optionset&id=' . $id . '&revision=' . (int) $data['snapshot']['revision'] . '&before_revision=' . $data['history']['next_before'], false)) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_NEXT') ?></a><?php endif ?>
  <script type="application/json" data-nfs-resource-data><?= json_encode(['resource' => $data['resource'], 'snapshot' => $data['snapshot'], 'can_edit' => $data['can_edit']], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</section>
