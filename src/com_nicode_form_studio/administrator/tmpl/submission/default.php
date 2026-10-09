<?php
declare(strict_types=1);
defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$data = $this->data; $record = $data['record'];
$valueText = static fn (mixed $value): string => is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$historyLinks = static function (string $kind) use ($record, $escape): void {
    $base = ['option' => 'com_nicode_form_studio', 'view' => 'submission', 'form_id' => $record['form_id'], 'id' => $record['id']];
    foreach ($record['history_before'] as $name => $before) { if ($before !== PHP_INT_MAX) { $base[$name . '_before'] = $before; } }
    if ($record[$kind . '_next_before'] !== null) {
        $next = array_replace($base, [$kind . '_before' => $record[$kind . '_next_before']]);
        echo '<p><a data-nfs-history-next="' . $kind . '" href="' . $escape(Route::_('index.php?' . http_build_query($next), false) . '#nfs-history-' . $kind) . '">' . $escape(Text::_('COM_NICODE_FORM_STUDIO_HISTORY_OLDER')) . '</a></p>';
    }
    if ($record['history_before'][$kind] !== PHP_INT_MAX) {
        unset($base[$kind . '_before']);
        echo '<p><a href="' . $escape(Route::_('index.php?' . http_build_query($base), false) . '#nfs-history-' . $kind) . '">' . $escape(Text::_('COM_NICODE_FORM_STUDIO_HISTORY_LATEST')) . '</a></p>';
    }
};
$renderAnswers = static function (array $nodes) use (&$renderAnswers, $record, $escape, $valueText): void {
    foreach ($nodes as $node) {
        if ($node['kind'] === 'group') {
            echo '<fieldset class="nfs-answer-group"><legend>' . $escape($node['label']) . '</legend>'; $renderAnswers($node['children']); echo '</fieldset>';
            continue;
        }
        $uuid = $node['uuid'];
        echo '<dl><dt>' . $escape($node['label']) . '</dt><dd><pre data-nfs-answer="' . $escape($uuid) . '">' . $escape($node['masked'] ? Text::_('COM_NICODE_FORM_STUDIO_SENSITIVE_MASKED') : $valueText($record['values'][$uuid])) . '</pre>';
        echo '<p data-nfs-option-label="' . $escape($uuid) . '">' . $escape($record['option_labels'][$uuid] ?? '') . '</p></dd></dl>';
    }
};
?>
<section class="nfs-admin" data-nfs-submission data-csrf="<?= $escape($data['csrf']) ?>" data-form-id="<?= (int) $record['form_id'] ?>" data-id="<?= (int) $record['id'] ?>">
  <h1><?= Text::_('COM_NICODE_FORM_STUDIO_SUBMISSION') ?></h1>
  <p><a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=submissions&form_id=' . $record['form_id'], false)) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_SUBMISSIONS') ?></a></p>
  <dl><?php foreach (['uuid', 'form_name', 'form_version_id', 'received_at', 'state', 'action_status', 'channel', 'locale', 'user_id', 'anonymized_at'] as $key): ?><dt><?= Text::_('COM_NICODE_FORM_STUDIO_' . strtoupper($key)) ?></dt><dd><?= $escape($record[$key] ?? '—') ?></dd><?php endforeach ?></dl>
  <p role="status" aria-live="polite" data-nfs-submission-status></p>
  <?php if ($record['can_manage']): ?><form data-nfs-state data-expected="<?= $escape($record['state']) ?>" class="nfs-admin-filters">
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_STATE') ?><select name="state"><?php foreach (\Nicode\FormStudio\Application\SubmissionAdministration::STATES as $state): ?><option value="<?= $state ?>" <?= $record['state'] === $state ? 'selected' : '' ?>><?= Text::_('COM_NICODE_FORM_STUDIO_RESPONSE_' . strtoupper($state)) ?></option><?php endforeach ?></select></label>
    <button type="submit" class="btn btn-secondary"><?= Text::_('COM_NICODE_FORM_STUDIO_CHANGE_STATE') ?></button>
  </form><?php endif ?>
  <?php if ($record['masked'] !== [] || $record['request_metadata_masked']): ?>
    <p data-nfs-masked><?= Text::_('COM_NICODE_FORM_STUDIO_SENSITIVE_MASKED') ?></p>
    <?php if ($record['can_reveal']): ?><button type="button" class="btn btn-secondary" data-nfs-reveal><?= Text::_('COM_NICODE_FORM_STUDIO_REVEAL') ?></button><?php endif ?>
  <?php endif ?>
  <div data-nfs-values><?php $renderAnswers($record['layout']); ?></div>
  <?php if ($record['request_metadata_masked']): ?><section data-nfs-request-metadata hidden><h2><?= Text::_('COM_NICODE_FORM_STUDIO_REQUEST_METADATA') ?></h2><dl></dl></section><?php endif ?>
  <section aria-labelledby="nfs-consent-title"><h2 id="nfs-consent-title"><?= Text::_('COM_NICODE_FORM_STUDIO_CONSENT_HISTORY') ?></h2>
    <div data-nfs-consents><?php foreach ($record['consents'] as $uuid => $consent): ?><article>
      <h3><?= $escape($record['labels'][$uuid] ?? $uuid) ?></h3>
      <dl><?php foreach (['accepted', 'text', 'received_at', 'form_version_id'] as $key): ?><dt><?= Text::_('COM_NICODE_FORM_STUDIO_CONSENT_' . strtoupper($key)) ?></dt><dd><?= $escape($key === 'accepted' ? Text::_($consent[$key] ? 'JYES' : 'JNO') : ($consent[$key] ?? '—')) ?></dd><?php endforeach ?></dl>
    </article><?php endforeach ?></div>
  </section>
  <h2><?= Text::_('COM_NICODE_FORM_STUDIO_FILES') ?></h2>
  <div data-nfs-files><?php foreach ($record['files'] as $file): ?><form method="post" action="index.php">
    <input type="hidden" name="option" value="com_nicode_form_studio"><input type="hidden" name="task" value="submission.download"><input type="hidden" name="format" value="raw">
    <input type="hidden" name="form_id" value="<?= (int) $record['form_id'] ?>"><input type="hidden" name="file" value="<?= $escape($file['uuid']) ?>"><input type="hidden" name="<?= $escape($data['csrf']) ?>" value="1">
    <button type="submit" class="btn btn-secondary"><?= Text::_('COM_NICODE_FORM_STUDIO_DOWNLOAD') ?>: <?= $escape($file['original_name']) ?> (<?= (int) $file['size_bytes'] ?> B)</button>
  </form><?php endforeach ?></div>
  <h2 id="nfs-history-actions"><?= Text::_('COM_NICODE_FORM_STUDIO_ACTION_HISTORY') ?></h2>
  <?php if ($data['retry_actions'] !== []): ?><details><summary><?= Text::_('COM_NICODE_FORM_STUDIO_RETRY_ACTIONS') ?></summary>
    <p><?= Text::_('COM_NICODE_FORM_STUDIO_RETRY_ACTIONS_HELP') ?></p>
    <form data-nfs-retry><input type="hidden" name="attempts" value="<?= $escape(json_encode($data['retry_actions'], JSON_THROW_ON_ERROR)) ?>"><button type="submit" class="btn btn-secondary"><?= Text::_('COM_NICODE_FORM_STUDIO_RETRY_ALL_FAILED') ?></button></form>
    <?php foreach ($data['retry_actions'] as $actionUuid => $attempt): ?><form data-nfs-retry><input type="hidden" name="attempts" value="<?= $escape(json_encode([$actionUuid => $attempt], JSON_THROW_ON_ERROR)) ?>"><button type="submit" class="btn btn-secondary"><?= Text::_('COM_NICODE_FORM_STUDIO_RETRY_ACTION') ?> <?= $escape($actionUuid) ?> · <?= (int) $attempt ?></button></form><?php endforeach ?>
  </details><?php endif ?>
  <div class="nfs-admin-table"><table class="table"><thead><tr><?php foreach (['ACTION_TYPE', 'ATTEMPT', 'STATE', 'CREATED_AT', 'RESULT_CODE', 'NEXT_RETRY_AT'] as $key): ?><th scope="col"><?= Text::_('COM_NICODE_FORM_STUDIO_' . $key) ?></th><?php endforeach ?></tr></thead><tbody>
  <?php foreach ($record['actions'] as $action): ?><tr><?php foreach (['action_type', 'attempt', 'state', 'created_at', 'result_code', 'next_retry_at'] as $key): ?><td><?= $escape($action[$key] ?? '—') ?></td><?php endforeach ?></tr><?php endforeach ?>
  </tbody></table></div>
  <?php $historyLinks('actions'); ?>
  <h2 id="nfs-history-notes"><?= Text::_('COM_NICODE_FORM_STUDIO_NOTES') ?></h2>
  <?php if ($record['can_manage'] && $record['anonymized_at'] === null): ?><form data-nfs-note>
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_NOTE_BODY') ?><textarea name="body" required maxlength="4000" rows="4"></textarea></label>
    <button type="submit" class="btn btn-secondary"><?= Text::_('COM_NICODE_FORM_STUDIO_ADD_NOTE') ?></button>
  </form><?php endif ?>
  <?php foreach ($record['notes'] as $note): ?><article><p><?= $escape($note['created_at']) ?> · <?= $escape($note['created_by']) ?></p><pre><?= $escape($note['body']) ?></pre></article><?php endforeach ?>
  <?php $historyLinks('notes'); ?>
  <?php if ($record['audit'] !== [] || $record['history_before']['audit'] !== PHP_INT_MAX): ?><h2 id="nfs-history-audit"><?= Text::_('COM_NICODE_FORM_STUDIO_AUDIT') ?></h2><ul>
    <?php foreach ($record['audit'] as $event): ?><li><?= $escape($event['created_at']) ?> · <?= $escape($event['event_type']) ?> · <?= $escape($event['actor_id']) ?><pre><?= $escape($event['safe_metadata']) ?></pre></li><?php endforeach ?>
  </ul><?php $historyLinks('audit'); ?><?php endif ?>
</section>
