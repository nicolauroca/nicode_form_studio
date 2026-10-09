<?php
declare(strict_types=1);
defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); $data = $this->data;
?>
<section class="nfs-admin">
  <a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=resources', false)) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_RESOURCES') ?></a>
  <h1><?= Text::_('COM_NICODE_FORM_STUDIO_DATA_SOURCES') ?></h1>
  <p><?= Text::_('COM_NICODE_FORM_STUDIO_SOURCE_RESOURCE_HELP') ?></p>
  <table class="table"><caption><?= Text::_('COM_NICODE_FORM_STUDIO_DATA_SOURCES') ?></caption><thead><tr><th scope="col"><?= Text::_('COM_NICODE_FORM_STUDIO_NAME') ?></th><th scope="col"><?= Text::_('COM_NICODE_FORM_STUDIO_SOURCE_PROVIDER') ?></th><th scope="col"><?= Text::_('COM_NICODE_FORM_STUDIO_RESOURCE_REVISION') ?></th><th scope="col"><?= Text::_('COM_NICODE_FORM_STUDIO_ENABLED') ?></th></tr></thead><tbody>
    <?php foreach ($data['rows'] as $row): ?><tr><th scope="row"><a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=datasource&id=' . (int) $row['id'], false)) ?>"><?= $escape($row['name']) ?></a></th><td><?= $escape($row['provider']) ?></td><td><?= (int) $row['revision'] ?></td><td><?= Text::_($row['enabled'] ? 'JYES' : 'JNO') ?></td></tr><?php endforeach ?>
  </tbody></table>
  <?php if ($data['next_before'] !== null): ?><a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=datasources&before=' . (int) $data['next_before'], false)) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_NEXT') ?></a><?php endif ?>
</section>
