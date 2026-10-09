<?php
declare(strict_types=1);
defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$data = $this->data; $filters = $data['filters'];
?>
<section class="nfs-admin" data-nfs-admin data-csrf="<?= $escape($data['csrf']) ?>">
  <h1><?= Text::_('COM_NICODE_FORM_STUDIO_FORMS') ?></h1>
  <p role="status" data-nfs-status aria-live="polite"></p>
  <?php if ($data['canCreate']): ?>
  <details><summary><?= Text::_('COM_NICODE_FORM_STUDIO_NEW') ?></summary>
    <form data-nfs-create class="nfs-admin-create">
      <label><?= Text::_('COM_NICODE_FORM_STUDIO_NAME') ?><input name="name" required maxlength="255"></label>
      <label><?= Text::_('COM_NICODE_FORM_STUDIO_ALIAS') ?><input name="alias" required maxlength="255" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" aria-describedby="nfs-alias-help"></label>
      <p id="nfs-alias-help"><?= Text::_('COM_NICODE_FORM_STUDIO_ALIAS_HELP') ?></p>
      <button class="btn btn-primary" type="submit"><?= Text::_('COM_NICODE_FORM_STUDIO_CREATE') ?></button>
    </form>
  </details>
  <?php endif ?>
  <form method="get" action="index.php" class="nfs-admin-filters">
    <input type="hidden" name="option" value="com_nicode_form_studio"><input type="hidden" name="view" value="forms">
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_SEARCH') ?><input type="search" name="search" value="<?= $escape($filters['search'] ?? '') ?>"></label>
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_STATE') ?><select name="state"><option value=""><?= Text::_('JALL') ?></option><?php foreach (['draft', 'published', 'unpublished', 'archived', 'trashed', 'deleting'] as $state): ?><option value="<?= $state ?>" <?= ($filters['state'] ?? '') === $state ? 'selected' : '' ?>><?= Text::_('COM_NICODE_FORM_STUDIO_STATE_' . strtoupper($state)) ?></option><?php endforeach ?></select></label>
    <label><?= Text::_('JFIELD_LANGUAGE_LABEL') ?><input name="language" maxlength="32" value="<?= $escape($filters['language'] ?? '') ?>"></label>
    <label><?= Text::_('JFIELD_ACCESS_LABEL') ?><input type="number" name="access" min="1" value="<?= $escape($filters['access'] ?? '') ?>"></label>
    <label><?= Text::_('JAUTHOR') ?><input type="number" name="author" min="1" value="<?= $escape($filters['author'] ?? '') ?>"></label>
    <button type="submit" class="btn btn-secondary"><?= Text::_('COM_NICODE_FORM_STUDIO_FILTER') ?></button>
    <a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=forms', false)) ?>"><?= Text::_('JSEARCH_FILTER_CLEAR') ?></a>
  </form>
  <fieldset data-nfs-form-selection><legend><?= Text::_('COM_NICODE_FORM_STUDIO_SELECTION_TITLE') ?></legend>
    <p data-nfs-selection-count aria-live="polite"></p>
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_SELECTION_OPERATION') ?><select>
      <?php foreach (['unpublished', 'publish', 'archived', 'trashed'] as $operation): ?><option value="<?= $operation ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_STATE_' . strtoupper($operation === 'publish' ? 'published' : $operation)) ?></option><?php endforeach ?>
    </select></label>
    <button type="button" class="btn btn-secondary" disabled><?= Text::_('COM_NICODE_FORM_STUDIO_SELECTION_APPLY') ?></button>
    <p><?= Text::_('COM_NICODE_FORM_STUDIO_SELECTION_HELP') ?></p>
  </fieldset>
  <div class="nfs-admin-table" role="region" tabindex="0" aria-label="<?= $escape(Text::_('COM_NICODE_FORM_STUDIO_FORMS')) ?>"><table class="table"><caption><?= Text::_('COM_NICODE_FORM_STUDIO_FORMS') ?></caption><thead><tr>
    <th scope="col"><input type="checkbox" data-nfs-select-all aria-label="<?= $escape(Text::_('COM_NICODE_FORM_STUDIO_SELECTION_ALL')) ?>"></th>
    <?php foreach (['STATE', 'NAME', 'ALIAS', 'ID', 'VERSION', 'FIELD_COUNT', 'SUBMISSION_COUNT', 'MODIFIED', 'AUTHOR', 'LANGUAGE', 'ACCESS'] as $column): ?><th scope="col"><?= Text::_('COM_NICODE_FORM_STUDIO_' . $column) ?></th><?php endforeach ?>
  </tr></thead><tbody>
  <?php foreach ($data['rows'] as $row): ?><tr>
    <td><input type="checkbox" data-nfs-select-form value="<?= (int) $row['id'] ?>" data-revision="<?= (int) $row['draft_revision'] ?>" data-name="<?= $escape($row['name']) ?>" data-can-trash="<?= $row['capabilities']['core.delete'] ? '1' : '0' ?>" aria-label="<?= $escape(Text::_('COM_NICODE_FORM_STUDIO_SELECTION_SELECT') . ' ' . $row['name'] . ' (#' . $row['id'] . ')') ?>" <?= $row['state'] === 'deleting' || !$row['capabilities']['core.edit.state'] || !$row['capabilities']['formstudio.forms.publish'] ? 'disabled' : '' ?>></td>
    <td data-nfs-row-state><?= Text::_('COM_NICODE_FORM_STUDIO_STATE_' . strtoupper($row['state'])) ?></td>
    <th scope="row"><?php if ($row['capabilities']['core.edit']): ?><a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=editor&id=' . $row['id'], false)) ?>"><?= $escape($row['name']) ?></a><?php else: ?><?= $escape($row['name']) ?><?php endif ?></th>
    <?php foreach (['alias', 'id', 'published_revision', 'field_count', 'submission_count', 'modified_at', 'created_by', 'language', 'access'] as $column): ?><td <?= $column === 'published_revision' ? 'data-nfs-row-version' : ($column === 'modified_at' ? 'data-nfs-row-modified' : '') ?>><?= $escape($row[$column] ?? '—') ?></td><?php endforeach ?>
  </tr><?php endforeach ?>
  <?php if (!$data['rows']): ?><tr><td colspan="12"><?= Text::_('JGLOBAL_NO_MATCHING_RESULTS') ?></td></tr><?php endif ?>
  </tbody></table></div>
  <?php if ($data['next_cursor'] !== null): ?><a class="btn btn-secondary" rel="next" href="<?= $escape(Route::_('index.php?' . http_build_query(['option' => 'com_nicode_form_studio', 'view' => 'forms'] + $filters + ['cursor' => $data['next_cursor']]), false)) ?>"><?= Text::_('JNEXT') ?></a><?php endif ?>
</section>
