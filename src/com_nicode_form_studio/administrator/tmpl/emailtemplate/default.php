<?php
declare(strict_types=1);
defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$data = $this->data; $record = $data['record'];
?>
<section class="nfs-admin" data-nfs-email-template data-id="<?= $record['id'] ?>" data-revision="<?= $record['revision'] ?>" data-csrf="<?= $escape($data['csrf']) ?>">
  <a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=templates&kind=email', false)) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_EMAIL_TEMPLATES') ?></a>
  <h1><?= $escape($record['name']) ?></h1>
  <p><?= Text::_('COM_NICODE_FORM_STUDIO_EMAIL_TEMPLATE_HELP') ?></p>
  <p role="status" aria-live="polite" data-nfs-template-status></p>
  <form><fieldset<?= $data['canResourceEdit'] ? '' : ' disabled' ?>>
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_NAME') ?><input name="name" required maxlength="255" value="<?= $escape($record['name']) ?>"></label>
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_TRANSLATION_LANGUAGE') ?><input name="language" required pattern="[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8}){0,3}" value="<?= $escape($record['language']) ?>"></label>
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_ACTION_SUBJECT') ?><input name="subject" maxlength="65536" value="<?= $escape($record['subject']) ?>"></label>
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_ACTION_BODY_TEXT') ?><textarea name="body_text" rows="10" maxlength="65536"><?= $escape($record['body_text']) ?></textarea></label>
    <label><?= Text::_('COM_NICODE_FORM_STUDIO_ACTION_BODY_HTML') ?><textarea name="body_html" rows="10" maxlength="65536"><?= $escape($record['body_html']) ?></textarea></label>
    <p><?= Text::_('COM_NICODE_FORM_STUDIO_TEMPLATE_TOKENS_HELP') ?></p>
    <code>{{form.name}} · {{form.uuid}} · {{submission.reference}} · {{submission.date}} · {{response.summary}} · {{input.contact.value}} · {{input.contact.label}} · {{input.contact.option_label}}</code>
    <?php if ($data['canResourceEdit']): ?><button class="btn btn-primary" type="submit"><?= Text::_('COM_NICODE_FORM_STUDIO_TEMPLATE_SAVE') ?></button><?php endif ?>
  </fieldset></form>
</section>
