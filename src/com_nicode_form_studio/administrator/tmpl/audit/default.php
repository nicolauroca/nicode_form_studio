<?php
declare(strict_types=1);
defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$data = $this->data;
$labels = ['correlation_id' => 'CORRELATION', 'form_id' => 'FORM', 'actor_id' => 'AUTHOR', 'submission_uuid' => 'REFERENCE', 'event_type' => 'EVENT', 'from' => 'DATE_FROM', 'to' => 'DATE_TO'];
?>
<section class="nfs-admin">
  <h1><?= Text::_('COM_NICODE_FORM_STUDIO_AUDIT') ?></h1>
  <p><a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=logs', false)) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_TECHNICAL_LOG') ?></a> · <a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=forms', false)) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_FORMS') ?></a></p>
  <p><?= Text::_('COM_NICODE_FORM_STUDIO_AUDIT_HELP') ?></p>
  <form method="get" action="index.php" class="nfs-admin-filters">
    <input type="hidden" name="option" value="com_nicode_form_studio"><input type="hidden" name="view" value="audit">
    <?php foreach ($labels as $key => $label): ?><label><?= Text::_('COM_NICODE_FORM_STUDIO_' . $label) ?><input name="<?= $key ?>" type="<?= in_array($key, ['form_id', 'actor_id'], true) ? 'number' : (in_array($key, ['from', 'to'], true) ? 'date' : 'text') ?>" <?= in_array($key, ['form_id', 'actor_id'], true) ? 'min="1"' : 'maxlength="64"' ?> value="<?= $escape($data['filters'][$key] ?? '') ?>"></label><?php endforeach ?>
    <button type="submit" class="btn btn-secondary"><?= Text::_('COM_NICODE_FORM_STUDIO_FILTER') ?></button>
    <a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=audit', false)) ?>"><?= Text::_('JSEARCH_FILTER_CLEAR') ?></a>
  </form>
  <div class="nfs-admin-table" tabindex="0" role="region" aria-label="<?= $escape(Text::_('COM_NICODE_FORM_STUDIO_AUDIT')) ?>"><table class="table"><caption><?= Text::_('COM_NICODE_FORM_STUDIO_AUDIT') ?></caption><thead><tr>
    <?php foreach (['CREATED', 'EVENT', 'AUTHOR', 'FORM', 'REFERENCE', 'CORRELATION', 'REFERENCES'] as $key): ?><th scope="col"><?= Text::_('COM_NICODE_FORM_STUDIO_' . $key) ?></th><?php endforeach ?>
  </tr></thead><tbody><?php foreach ($data['rows'] as $row): ?><tr>
    <th scope="row"><?= $escape($row['created_at']) ?> UTC</th>
    <?php foreach (['event_type', 'actor_id', 'form_id', 'submission_uuid', 'correlation_id'] as $key): ?><td><?= $escape($row[$key] ?? '—') ?></td><?php endforeach ?>
    <td><?php foreach ($row['details'] as $key => $value): ?><div><?= $escape(Text::_('COM_NICODE_FORM_STUDIO_AUDIT_' . strtoupper($key))) ?>: <?= $key === 'state' ? $escape(Text::_('COM_NICODE_FORM_STUDIO_STATE_' . strtoupper($value))) : $escape($value) ?></div><?php endforeach ?></td>
  </tr><?php endforeach ?>
  <?php if ($data['rows'] === []): ?><tr><td colspan="7"><?= Text::_('JGLOBAL_NO_MATCHING_RESULTS') ?></td></tr><?php endif ?>
  </tbody></table></div>
  <?php if ($data['next_before'] !== null): ?><a rel="next" href="<?= $escape(Route::_('index.php?' . http_build_query(['option' => 'com_nicode_form_studio', 'view' => 'audit', 'before' => $data['next_before']] + $data['filters']), false)) ?>"><?= Text::_('JNEXT') ?></a><?php endif ?>
</section>
