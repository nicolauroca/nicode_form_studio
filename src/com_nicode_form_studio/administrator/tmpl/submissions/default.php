<?php
declare(strict_types=1);
defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$data = $this->data; $filters = $data['filters'];
?>
<section class="nfs-admin" data-csrf="<?= $escape($data['csrf']) ?>">
  <h1><?= Text::_('COM_NICODE_FORM_STUDIO_SUBMISSIONS') ?></h1>
  <p><a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=forms', false)) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_FORMS') ?></a></p>
  <form method="get" action="index.php" class="nfs-admin-filters" data-nfs-search>
    <input type="hidden" name="option" value="com_nicode_form_studio"><input type="hidden" name="view" value="submissions">
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_FORM') ?><select name="form_id" aria-label="<?= $escape(Text::_('COM_NICODE_FORM_STUDIO_FORM')) ?>"><option value=""><?= Text::_('JALL') ?></option><?php foreach ($data['forms'] as $form): ?><option value="<?= $form['id'] ?>" <?= (int) ($filters['form_id'] ?? 0) === $form['id'] ? 'selected' : '' ?>><?= $escape($form['name']) ?></option><?php endforeach ?></select></label>
    <?php foreach (['uuid', 'state', 'channel', 'action_status', 'received_from', 'received_to'] as $key): ?>
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_' . strtoupper($key)) ?><input name="<?= $key ?>" value="<?= $escape($filters[$key] ?? '') ?>" maxlength="64" <?= str_starts_with($key, 'received_') ? 'placeholder="YYYY-MM-DD HH:MM:SS (UTC)"' : '' ?>></label>
    <?php endforeach ?>
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_USER_ID') ?><input name="user_id" type="number" min="1" value="<?= $escape($filters['user_id'] ?? '') ?>"></label>
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_ID') ?><input name="id" type="number" min="1" value="<?= $escape($filters['id'] ?? '') ?>"></label>
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_SORT') ?><select name="sort"><?php foreach ($data['sorts'] as $sort): ?><option value="<?= $escape($sort) ?>" <?= $sort === $data['sort'] ? 'selected' : '' ?>><?= Text::_('COM_NICODE_FORM_STUDIO_SORT_' . strtoupper($sort)) ?></option><?php endforeach ?></select></label>
    <?php if ($data['filter_fields'] !== []): ?><fieldset class="nfs-search-fields"><legend><?= Text::_('COM_NICODE_FORM_STUDIO_FIELD_FILTERS') ?> · <?= Text::_('COM_NICODE_FORM_STUDIO_VERSION') ?> <?= (int) $data['schema_version'] ?></legend>
      <p><?= Text::_('COM_NICODE_FORM_STUDIO_FIELD_FILTERS_HELP') ?></p>
      <div data-nfs-field-filters></div><button type="button" class="btn btn-secondary" data-nfs-add-filter><?= Text::_('COM_NICODE_FORM_STUDIO_ADD_FILTER') ?></button>
    </fieldset><?php endif ?>
    <input type="hidden" name="field_filters" value="<?= $escape(json_encode($data['field_filters'], JSON_THROW_ON_ERROR)) ?>">
    <script type="application/json" data-nfs-filter-schema><?= json_encode($data['filter_fields'], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <?php if ($data['column_fields'] !== []): ?><details class="nfs-search-fields"><summary><?= Text::_('COM_NICODE_FORM_STUDIO_ANSWER_COLUMNS') ?></summary>
      <?php foreach ($data['column_fields'] as $field): ?><label><input type="checkbox" name="columns[]" value="<?= $escape($field['uuid']) ?>" <?= in_array($field['uuid'], $data['columns'], true) ? 'checked' : '' ?>><?= $escape($field['label']) ?></label><?php endforeach ?>
    </details><?php endif ?>
    <button class="btn btn-primary" type="submit"><?= Text::_('COM_NICODE_FORM_STUDIO_FILTER') ?></button>
    <a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=submissions', false)) ?>"><?= Text::_('JSEARCH_FILTER_CLEAR') ?></a>
  </form>
  <?php require __DIR__ . '/jobs.php'; ?>
  <details><summary><?= Text::_('COM_NICODE_FORM_STUDIO_SAVED_VIEWS') ?></summary>
    <form data-nfs-save-view class="nfs-admin-filters"><label><?= Text::_('COM_NICODE_FORM_STUDIO_NAME') ?><input name="name" required maxlength="255"></label><button type="submit" class="btn btn-secondary"><?= Text::_('COM_NICODE_FORM_STUDIO_SAVE_VIEW') ?></button></form>
    <p role="status" aria-live="polite" data-nfs-search-status></p>
    <ul><?php foreach ($data['saved_views']['rows'] as $saved): ?><li><a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=submissions&preset=' . $saved['id'], false)) ?>"><?= $escape($saved['name']) ?></a> <button type="button" class="btn btn-link" data-nfs-remove-view="<?= (int) $saved['id'] ?>" aria-label="<?= $escape(Text::_('COM_NICODE_FORM_STUDIO_REMOVE') . ': ' . $saved['name']) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_REMOVE') ?></button></li><?php endforeach ?></ul>
    <?php if ($data['saved_views']['before'] !== null): ?><a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=submissions&views_before=' . $data['saved_views']['before'], false)) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_OLDER_VIEWS') ?></a><?php endif ?>
  </details>
  <div class="nfs-admin-table"><table class="table"><caption><?= Text::_('COM_NICODE_FORM_STUDIO_SUBMISSIONS') ?></caption><thead><tr>
    <th scope="col" <?= str_starts_with($data['sort'], 'id_') ? 'aria-sort="' . (str_ends_with($data['sort'], '_asc') ? 'ascending' : 'descending') . '"' : '' ?>><?= Text::_('COM_NICODE_FORM_STUDIO_ID') ?></th>
    <?php foreach (['UUID', 'FORM', 'VERSION', 'RECEIVED', 'STATE', 'CHANNEL', 'ACTION_STATUS'] as $key): ?><th scope="col" <?= $key === 'RECEIVED' && str_starts_with($data['sort'], 'received_at_') ? 'aria-sort="' . (str_ends_with($data['sort'], '_asc') ? 'ascending' : 'descending') . '"' : '' ?>><?= Text::_('COM_NICODE_FORM_STUDIO_' . $key) ?></th><?php endforeach ?>
    <?php $columnLabels = array_column($data['column_fields'], 'label', 'uuid'); foreach ($data['columns'] as $column): ?><th scope="col"><?= $escape($columnLabels[$column]) ?></th><?php endforeach ?>
  </tr></thead><tbody>
    <?php foreach ($data['rows'] as $row): ?><tr>
      <td><?= (int) $row['id'] ?></td>
      <th scope="row"><a href="<?= $escape(Route::_('index.php?' . http_build_query(['option' => 'com_nicode_form_studio', 'view' => 'submission', 'form_id' => $row['form_id'], 'id' => $row['id']]), false)) ?>"><?= $escape($row['uuid']) ?></a></th>
      <?php foreach (['form_name', 'form_version_id', 'received_at', 'state', 'channel', 'action_status'] as $key): ?><td><?= $escape($row[$key]) ?></td><?php endforeach ?>
      <?php foreach ($row['cells'] as $cell): ?><td title="<?= $escape($cell['label']) ?>"><?php if ($cell['masked']): ?><?= Text::_('COM_NICODE_FORM_STUDIO_SENSITIVE_MASKED') ?><?php elseif (!$cell['present']): ?>—<?php else: ?><pre><?= $escape(is_string($cell['value']) ? $cell['value'] : json_encode($cell['value'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) ?></pre><?php if ($cell['option_label'] !== null): ?><small><?= $escape($cell['option_label']) ?></small><?php endif ?><?php endif ?></td><?php endforeach ?>
    </tr><?php endforeach ?>
    <?php if (!$data['rows']): ?><tr><td colspan="<?= 8 + count($data['columns']) ?>"><?= Text::_('JGLOBAL_NO_MATCHING_RESULTS') ?></td></tr><?php endif ?>
  </tbody></table></div>
  <?php if ($data['next_cursor'] !== null): ?><a class="btn btn-secondary" rel="next" href="<?= $escape(Route::_('index.php?' . http_build_query(['option' => 'com_nicode_form_studio', 'view' => 'submissions'] + $filters + ['sort' => $data['sort'], 'columns' => $data['columns'], 'field_filters' => json_encode($data['field_filters'], JSON_THROW_ON_ERROR), 'cursor' => $data['next_cursor']]), false)) ?>"><?= Text::_('JNEXT') ?></a><?php endif ?>
</section>
