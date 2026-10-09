<?php
declare(strict_types=1);
defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); $data = $this->data; $record = $data['record'];
?>
<section class="nfs-admin" data-nfs-source-resource data-id="<?= (int) $record['id'] ?>" data-revision="<?= $record['revision'] ?>" data-csrf="<?= $escape($data['csrf']) ?>">
  <a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=datasources', false)) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_DATA_SOURCES') ?></a>
  <h1><?= $escape($record['name']) ?></h1>
  <p><?= Text::_('COM_NICODE_FORM_STUDIO_SOURCE_RESOURCE_HELP') ?></p>
  <p role="status" aria-live="polite" data-nfs-source-status></p>
  <form><fieldset <?= $data['canResourceEdit'] ? '' : 'disabled' ?>><legend><?= Text::_('COM_NICODE_FORM_STUDIO_DATA_SOURCES') ?></legend>
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_NAME') ?><input name="name" required maxlength="255" value="<?= $escape($record['name']) ?>"></label>
    <label><input type="checkbox" name="enabled" value="1" <?= $record['enabled'] ? 'checked' : '' ?>><?= Text::_('COM_NICODE_FORM_STUDIO_ENABLED') ?></label>
    <button class="btn btn-primary" type="submit"><?= Text::_('COM_NICODE_FORM_STUDIO_SOURCE_RESOURCE_SAVE') ?></button>
  </fieldset></form>
  <dl><dt><?= Text::_('COM_NICODE_FORM_STUDIO_SOURCE_PROVIDER') ?></dt><dd><?= $escape($record['provider'] . ' · ' . $record['provider_version']) ?></dd><dt><?= Text::_('COM_NICODE_FORM_STUDIO_RESOURCE_REVISION') ?></dt><dd data-nfs-source-revision><?= $record['revision'] ?></dd></dl>
  <details><summary><?= Text::_('COM_NICODE_FORM_STUDIO_SOURCE_RESOURCE_CONFIGURATION') ?></summary><pre><?= $escape(json_encode($record['definition'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?></pre></details>
</section>
