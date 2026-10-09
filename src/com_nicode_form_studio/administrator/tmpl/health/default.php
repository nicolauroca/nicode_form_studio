<?php
declare(strict_types=1);
defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
$escape = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$health = $this->data['health'];
?>
<section class="nfs-admin">
  <h1><?= Text::_('COM_NICODE_FORM_STUDIO_HEALTH') ?></h1>
  <p><a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=logs', false)) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_TECHNICAL_LOG') ?></a></p>
  <p><a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=forms', false)) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_FORMS') ?></a> · <a href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=jobs', false)) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_JOBS') ?></a></p>
  <p>Joomla <?= $escape($health['joomla']) ?> · PHP <?= $escape($health['php']) ?> · FormSpec <?= $escape($health['formspec']) ?></p>
  <details><summary><?= Text::_('COM_NICODE_FORM_STUDIO_HEALTH_PACKAGE') ?></summary><dl><?php foreach ($health['versions'] as $extension => $version): ?><dt><?= $escape($extension) ?></dt><dd><?= $escape($version) ?></dd><?php endforeach ?></dl></details>
  <p><?= Text::_('COM_NICODE_FORM_STUDIO_HEALTH_HELP') ?></p>
  <table class="table"><caption><?= Text::_('COM_NICODE_FORM_STUDIO_HEALTH') ?></caption><thead><tr><th scope="col"><?= Text::_('COM_NICODE_FORM_STUDIO_NAME') ?></th><th scope="col"><?= Text::_('COM_NICODE_FORM_STUDIO_STATE') ?></th></tr></thead><tbody>
  <?php foreach ($health['checks'] as $name => $check): ?><tr>
    <th scope="row"><?= Text::_('COM_NICODE_FORM_STUDIO_HEALTH_' . strtoupper(str_replace('-', '_', $name))) ?></th>
    <td><?= Text::_('COM_NICODE_FORM_STUDIO_HEALTH_' . strtoupper($check['status'])) ?>
      <?php if (isset($check['count'])): ?> (<?= (int) $check['count'] ?><?= $check['more'] ? '+' : '' ?>)<?php endif ?>
      <?php if (isset($check['detail']) && $check['detail'] !== ''): ?><br><small><?= $escape($check['detail']) ?></small><?php endif ?>
      <?php if (isset($check['reason'])): ?><br><small><?= Text::_('COM_NICODE_FORM_STUDIO_HEALTH_' . strtoupper($check['reason'])) ?></small><?php endif ?>
      <?php if (isset($check['last'])): ?><br><small><?= $escape($check['last']['state']) ?> · <?= $escape($check['last']['finished_at'] ?? $check['last']['created_at']) ?> UTC</small><?php endif ?>
    </td></tr><?php endforeach ?>
  </tbody></table>
  <h2><?= Text::_('COM_NICODE_FORM_STUDIO_HEALTH_PHP_LIMITS') ?></h2>
  <p><?= Text::_('COM_NICODE_FORM_STUDIO_HEALTH_PHP_LIMITS_HELP') ?></p>
  <table class="table"><caption><?= Text::_('COM_NICODE_FORM_STUDIO_HEALTH_PHP_LIMITS') ?></caption><thead><tr><th scope="col"><?= Text::_('COM_NICODE_FORM_STUDIO_NAME') ?></th><th scope="col"><?= Text::_('COM_NICODE_FORM_STUDIO_VALUE') ?></th></tr></thead><tbody>
    <?php foreach ($health['php_limits'] as $directive => $value): ?><tr><th scope="row"><code><?= $escape($directive) ?></code></th><td><?= $value === null ? Text::_('COM_NICODE_FORM_STUDIO_HEALTH_LIMIT_UNKNOWN') : $escape($value) ?></td></tr><?php endforeach ?>
  </tbody></table>
  <a class="btn btn-secondary" href="<?= $escape(Route::_('index.php?option=com_nicode_form_studio&view=health', false)) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_REFRESH') ?></a>
  <details>
    <summary><?= Text::_('COM_NICODE_FORM_STUDIO_SUPPORT_SUMMARY') ?></summary>
    <p id="nfs-support-help"><?= Text::_('COM_NICODE_FORM_STUDIO_SUPPORT_SUMMARY_HELP') ?></p>
    <label for="nfs-support-summary"><?= Text::_('COM_NICODE_FORM_STUDIO_SUPPORT_SUMMARY') ?></label>
    <textarea id="nfs-support-summary" class="form-control" rows="12" readonly aria-describedby="nfs-support-help" spellcheck="false"><?= $escape(\Nicode\FormStudio\Health\SupportSummary::encode($health)) ?></textarea>
  </details>
</section>
