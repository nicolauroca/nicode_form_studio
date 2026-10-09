<?php
declare(strict_types=1);
defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$data = $this->data;
$tabs = ['fields' => 'COM_NICODE_FORM_STUDIO_TAB_FIELDS', 'logic' => 'COM_NICODE_FORM_STUDIO_LOGIC', 'validation' => 'COM_NICODE_FORM_STUDIO_TAB_VALIDATION', 'actions' => 'COM_NICODE_FORM_STUDIO_ACTIONS', 'privacy' => 'COM_NICODE_FORM_STUDIO_DATA_PRIVACY', 'security' => 'COM_NICODE_FORM_STUDIO_SECURITY', 'confirmation' => 'COM_NICODE_FORM_STUDIO_AFTER_SUBMIT', 'translations' => 'COM_NICODE_FORM_STUDIO_TRANSLATIONS'];
if ($data['canSettings'] || $data['canPublish']) { $tabs['publication'] = 'COM_NICODE_FORM_STUDIO_PUBLICATION'; }
if ($data['canPermissions']) { $tabs['permissions'] = 'COM_NICODE_FORM_STUDIO_PERMISSIONS'; }
$tabs += ['preview' => 'JGLOBAL_PREVIEW', 'versions' => 'COM_NICODE_FORM_STUDIO_VERSIONS'];
$panel = static function (string $name, string $attributes = ''): void {
    echo '<section id="nfs-panel-' . $name . '" data-nfs-tab-panel="' . $name . '" role="tabpanel" aria-labelledby="nfs-tab-' . $name . '" tabindex="0"' . ($name === 'fields' ? '' : ' hidden') . ' ' . $attributes . '>';
};
?>
<section class="nfs-admin" data-nfs-admin data-nfs-editor data-csrf="<?= $escape($data['csrf']) ?>">
  <h1 data-nfs-form-title><?= $escape($data['form']['name']) ?></h1>
  <p><strong data-nfs-form-state><?= Text::_('COM_NICODE_FORM_STUDIO_STATE_' . strtoupper($data['form']['state'])) ?></strong> · <?= Text::_('COM_NICODE_FORM_STUDIO_ID') ?> <?= (int) $data['form']['id'] ?></p>
  <p role="status" data-nfs-status aria-live="polite"></p>

  <section data-nfs-diagnostics hidden aria-live="polite"><h2><?= Text::_('COM_NICODE_FORM_STUDIO_DIAGNOSTICS') ?></h2><ul></ul></section>
  <div class="nfs-editor-tabs" role="tablist" aria-label="<?= $escape(Text::_('COM_NICODE_FORM_STUDIO_EDITOR_SECTIONS')) ?>">
    <?php foreach ($tabs as $name => $label): ?><button type="button" role="tab" id="nfs-tab-<?= $name ?>" data-nfs-tab="<?= $name ?>" aria-controls="nfs-panel-<?= $name ?>" aria-selected="<?= $name === 'fields' ? 'true' : 'false' ?>" tabindex="<?= $name === 'fields' ? '0' : '-1' ?>"><?= Text::_($label) ?></button><?php endforeach ?>
  </div>
  <?php $panel('fields'); ?>
  <label><?= Text::_('COM_NICODE_FORM_STUDIO_NAME') ?><input data-nfs-name required maxlength="255" value="<?= $escape($data['draft']['name']) ?>"></label>
  <p><?= Text::_('COM_NICODE_FORM_STUDIO_DRAFT_HELP') ?></p>
  <div class="nfs-builder">
    <section aria-labelledby="nfs-palette-title"><h2 id="nfs-palette-title"><?= Text::_('COM_NICODE_FORM_STUDIO_PALETTE') ?></h2><div data-nfs-palette></div></section>
    <section aria-labelledby="nfs-tree-title"><h2 id="nfs-tree-title"><?= Text::_('COM_NICODE_FORM_STUDIO_STRUCTURE') ?></h2><div data-nfs-tree></div></section>
    <section aria-labelledby="nfs-inspector-title"><h2 id="nfs-inspector-title"><?= Text::_('COM_NICODE_FORM_STUDIO_PROPERTIES') ?></h2><div data-nfs-inspector></div></section>
  </div>
  </section>
  <?php $panel('logic', 'data-nfs-logic-panel'); ?><h2><?= Text::_('COM_NICODE_FORM_STUDIO_LOGIC') ?></h2><div data-nfs-logic></div></section>
  <?php $panel('validation', 'data-nfs-validator-panel'); ?><h2><?= Text::_('COM_NICODE_FORM_STUDIO_CROSS_VALIDATION') ?></h2><div data-nfs-validators></div></section>
  <?php $panel('actions', 'data-nfs-actions-panel'); ?><h2><?= Text::_('COM_NICODE_FORM_STUDIO_ACTIONS') ?></h2><div data-nfs-actions></div></section>
  <?php $panel('privacy', ''); ?><h2><?= Text::_('COM_NICODE_FORM_STUDIO_DATA_PRIVACY') ?></h2><div data-nfs-privacy></div></section>
  <?php $panel('security', ''); ?><h2><?= Text::_('COM_NICODE_FORM_STUDIO_SECURITY') ?></h2><div data-nfs-security></div></section>
  <?php $panel('confirmation', 'data-nfs-conditional-panel'); ?>
    <h2><?= Text::_('COM_NICODE_FORM_STUDIO_AFTER_SUBMIT') ?></h2><div data-nfs-after-submit></div>
    <h2><?= Text::_('COM_NICODE_FORM_STUDIO_CONDITIONAL_MESSAGES') ?></h2><div data-nfs-conditional-messages></div>
  </section>
  <?php $panel('translations', ''); ?><h2><?= Text::_('COM_NICODE_FORM_STUDIO_TRANSLATIONS') ?></h2><div data-nfs-translations></div></section>
  <?php if ($data['canSettings'] || $data['canPublish']): $panel('publication'); ?>
  <h2><?= Text::_('COM_NICODE_FORM_STUDIO_PUBLICATION') ?></h2>
  <?php if ($data['canPublish']): ?><label><?= Text::_('COM_NICODE_FORM_STUDIO_VERSION_COMMENT') ?><input data-nfs-publish-comment maxlength="4000"></label><?php endif ?>
  <?php if ($data['canSettings']): ?>
    <p><?= Text::_('COM_NICODE_FORM_STUDIO_PUBLICATION_HELP') ?></p>
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_ALIAS') ?><input data-nfs-setting="alias" required maxlength="255" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" value="<?= $escape($data['form']['alias']) ?>"></label>
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_ACCESS') ?><select data-nfs-setting="access"><?php foreach ($data['accessLevels'] as $level): ?><option value="<?= (int) $level['id'] ?>" <?= (int) $level['id'] === (int) $data['form']['access'] ? 'selected' : '' ?>><?= $escape($level['title']) ?></option><?php endforeach ?></select></label>
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_LANGUAGE') ?><select data-nfs-setting="language"><option value="*" <?= $data['form']['language'] === '*' ? 'selected' : '' ?>><?= Text::_('JALL') ?></option><?php foreach ($data['languages'] as $language): ?><option value="<?= $escape($language['lang_code']) ?>" <?= $language['lang_code'] === $data['form']['language'] ? 'selected' : '' ?>><?= $escape($language['title']) ?></option><?php endforeach ?></select></label>
    <?php foreach (['publish_up', 'publish_down'] as $key): ?>
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_' . strtoupper($key)) ?><input type="datetime-local" step="1" data-nfs-setting="<?= $key ?>" value="<?= $escape($data['form'][$key] === null ? '' : str_replace(' ', 'T', substr($data['form'][$key], 0, 19))) ?>"></label>
    <?php endforeach ?>
    <button type="button" class="btn btn-secondary" data-nfs-command="settings"><?= Text::_('COM_NICODE_FORM_STUDIO_APPLY_SETTINGS') ?></button>
  <?php endif ?>
  </section>
  <?php endif ?>
  <?php if ($data['canPermissions']): $panel('permissions'); ?>
  <h2><?= Text::_('COM_NICODE_FORM_STUDIO_PERMISSIONS') ?></h2>
    <p><?= Text::_('COM_NICODE_FORM_STUDIO_PERMISSIONS_HELP') ?></p>
    <div data-nfs-permissions><button type="button" class="btn btn-secondary" data-nfs-command="permissions"><?= Text::_('COM_NICODE_FORM_STUDIO_LOAD_PERMISSIONS') ?></button></div>
  </section>
  <?php endif ?>

  <?php $panel('versions', 'data-nfs-versions'); ?><h2><?= Text::_('COM_NICODE_FORM_STUDIO_VERSIONS') ?></h2><button type="button" class="btn btn-secondary" data-nfs-command="history"><?= Text::_('COM_NICODE_FORM_STUDIO_REFRESH') ?></button><div></div></section>
  <?php $panel('preview', 'data-nfs-preview'); ?>
    <h2 class="visually-hidden"><?= Text::_('JGLOBAL_PREVIEW') ?></h2><p><?= Text::_('COM_NICODE_FORM_STUDIO_PREVIEW_HELP') ?></p>
    <p data-nfs-preview-empty><?= Text::_('COM_NICODE_FORM_STUDIO_PREVIEW_EMPTY') ?></p>
    <div class="nfs-preview-tools">
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_PREVIEW_VIEWPORT') ?>
      <select data-nfs-preview-viewport>
        <option value="1280"><?= Text::_('COM_NICODE_FORM_STUDIO_PREVIEW_DESKTOP') ?> (1280 px)</option>
        <option value="800"><?= Text::_('COM_NICODE_FORM_STUDIO_PREVIEW_TABLET') ?> (800 px)</option>
        <option value="390"><?= Text::_('COM_NICODE_FORM_STUDIO_PREVIEW_MOBILE') ?> (390 px)</option>
      </select>
    </label>
    <button type="button" class="btn btn-secondary" data-nfs-command="preview"><?= Text::_('COM_NICODE_FORM_STUDIO_REFRESH') ?></button>
    </div>
    <div class="nfs-preview-viewport" hidden><iframe width="1280" sandbox="allow-same-origin" title="<?= Text::_('JGLOBAL_PREVIEW') ?>"></iframe></div>
  </section>
  <script type="application/json" data-nfs-editor-data><?= json_encode($data, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</section>
