<?php
declare(strict_types=1);
defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
$escape = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$data = $this->data;
?>
<section class="nfs-admin">
  <h1><?= Text::_('COM_NICODE_FORM_STUDIO_TECHNICAL_LOG') ?></h1>
  <p><a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=audit', false)) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_AUDIT') ?></a></p>
  <p><a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=health', false)) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_HEALTH') ?></a></p>
  <p><?= Text::_('COM_NICODE_FORM_STUDIO_TECHNICAL_LOG_HELP') ?></p>
  <form method="get" action="index.php">
    <input type="hidden" name="option" value="com_nicode_form_studio"><input type="hidden" name="view" value="logs">
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_CORRELATION') ?><input name="correlation" maxlength="36" value="<?= $escape($data['correlation']) ?>"></label>
    <button class="btn btn-secondary" type="submit"><?= Text::_('COM_NICODE_FORM_STUDIO_FILTER') ?></button>
    <a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=logs', false)) ?>"><?= Text::_('JSEARCH_FILTER_CLEAR') ?></a>
  </form>
  <div class="nfs-admin-table"><table class="table"><caption><?= Text::_('COM_NICODE_FORM_STUDIO_TECHNICAL_LOG') ?></caption><thead><tr>
    <?php foreach (['CREATED', 'LEVEL', 'EVENT', 'CORRELATION', 'REFERENCES'] as $key): ?><th scope="col"><?= Text::_('COM_NICODE_FORM_STUDIO_' . $key) ?></th><?php endforeach ?>
  </tr></thead><tbody><?php foreach ($data['rows'] as $row): ?><tr>
    <th scope="row"><?= $escape($row['created_at']) ?> UTC</th><td><?= $escape($row['level']) ?></td><td><?= $escape($row['event_type']) ?></td><td><?= $escape($row['correlation_id']) ?></td>
    <td><?php foreach (['form_uuid', 'version_id', 'submission_uuid', 'action_run_id', 'job_id'] as $key): ?><?php if ($row[$key] !== null): ?><div><?= $escape($key) ?>: <?= $escape($row[$key]) ?></div><?php endif ?><?php endforeach ?></td>
  </tr><?php endforeach ?></tbody></table></div>
  <?php if ($data['next_before'] !== null): ?><a rel="next" href="<?= $escape(Route::_('index.php?' . http_build_query(['option' => 'com_nicode_form_studio', 'view' => 'logs', 'before' => $data['next_before'], 'correlation' => $data['correlation']]), false)) ?>"><?= Text::_('JNEXT') ?></a><?php endif ?>
</section>
