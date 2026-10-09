<?php
declare(strict_types=1);
defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
$escape = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$label = static fn (string $key): string => Text::_('COM_NICODE_FORM_STUDIO_DASH_' . strtoupper($key));
$url = static fn (string $view, array $params = []): string => Route::_('index.php?' . http_build_query(['option' => 'com_nicode_form_studio', 'view' => $view] + $params), false);
$data = $this->data; $report = $data['dashboard'];
$groups = [
    'forms' => ['forms_published', 'forms_unpublished', 'forms_draft', 'forms_archived', 'forms_trashed', 'forms_deleting'],
    'submissions' => ['responses_7', 'responses_30', 'spam', 'response_errors', 'actions_failed', 'emails_failed', 'webhooks_failed', 'storage_bytes', 'index_pending'],
    'diagnostics' => ['forms_invalid', 'forms_warning', 'forms_action', 'forms_captcha', 'forms_provider', 'forms_unavailable'],
];
?>
<section class="nfs-admin" data-nfs-dashboard>
  <h1><?= $label('title') ?></h1>
  <p><?= $label('scope') ?></p>
  <p><?= $label('observed') ?>: <time><?= $escape($report['observed_at']) ?> UTC</time> · <a href="<?= $escape($url('dashboard')) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_REFRESH') ?></a></p>
  <?php foreach ($groups as $group => $keys): ?>
  <h2><?= $label($group) ?></h2>
  <?php if ($group === 'submissions'): ?><p><?= Text::sprintf('COM_NICODE_FORM_STUDIO_DASH_READABLE', $report['readable_forms']) ?></p><?php endif ?>
  <?php if ($group === 'diagnostics'): ?><p><?= Text::sprintf('COM_NICODE_FORM_STUDIO_DASH_DIAGNOSED', $report['diagnosed_forms']) ?></p><?php endif ?>
  <dl class="nfs-dashboard-metrics">
    <?php foreach ($keys as $key): ?><div><dt><?= $label($key) ?></dt><dd data-nfs-metric="<?= $escape($key) ?>"><?= (int) $report['metrics'][$key] ?></dd></div><?php endforeach ?>
  </dl>
  <?php endforeach ?>
  <h2><?= $label('alerts') ?></h2>
  <p><?= $label('alerts_help') ?></p>
  <?php if ($report['alerts'] !== []): ?><ul><?php foreach ($report['alerts'] as $alert): ?><li><a href="<?= $escape($url('editor', ['id' => $alert['form_id']])) ?>"><?= $escape($alert['name']) ?> (#<?= (int) $alert['form_id'] ?>)</a>: <?= $label('forms_' . $alert['kind']) ?></li><?php endforeach ?></ul><?php endif ?>
  <?php if ($report['metrics']['index_pending'] > 0): ?><p><a href="<?= $escape($url('submissions')) ?>"><?= $label('index_pending') ?>: <?= (int) $report['metrics']['index_pending'] ?></a></p><?php endif ?>
  <?php if ($report['technical_errors'] !== null): ?><p><a href="<?= $escape($url('logs')) ?>"><?= $label('technical_errors') ?>: <?= (int) $report['technical_errors'] ?></a></p><?php endif ?>
  <?php if ($report['disabled_sources'] !== null): ?><p><a href="<?= $escape($url('datasources')) ?>"><?= $label('disabled_sources') ?>: <?= (int) $report['disabled_sources'] ?></a></p><?php endif ?>
  <?php if ($report['repeated_errors'] !== []): ?><h3><?= $label('repeated_errors') ?></h3><ul><?php foreach ($report['repeated_errors'] as $row): ?><li><a href="<?= $escape($url('logs')) ?>"><?= $escape($row['event_type']) ?></a>: <?= (int) $row['total'] ?></li><?php endforeach ?></ul><?php endif ?>
  <?php if ($report['health_alerts'] !== []): ?><h3><?= Text::_('COM_NICODE_FORM_STUDIO_HEALTH') ?></h3><ul><?php foreach ($report['health_alerts'] as $name => $status): ?><li><a href="<?= $escape($url('health')) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_HEALTH_' . strtoupper(str_replace('-', '_', $name))) ?></a>: <?= Text::_('COM_NICODE_FORM_STUDIO_HEALTH_' . strtoupper($status)) ?></li><?php endforeach ?></ul><?php endif ?>
  <h2><a href="<?= $escape($url('jobs')) ?>"><?= Text::_('COM_NICODE_FORM_STUDIO_JOBS') ?></a></h2>
  <dl class="nfs-dashboard-metrics"><?php foreach ($report['jobs'] as $state => $count): ?><div><dt><?= $label('jobs_' . $state) ?></dt><dd><?= (int) $count ?></dd></div><?php endforeach ?></dl>
  <p><?= $label('cleanup_help') ?></p>
  <div class="nfs-admin-table"><table class="table"><caption><?= $label('recent') ?></caption><thead><tr><th scope="col"><?= Text::_('COM_NICODE_FORM_STUDIO_NAME') ?></th><th scope="col">ID</th><th scope="col"><?= Text::_('COM_NICODE_FORM_STUDIO_STATE') ?></th><th scope="col"><?= $label('received') ?></th></tr></thead><tbody>
  <?php foreach ($report['recent'] as $row): ?><tr><th scope="row"><a href="<?= $escape($url('submission', ['form_id' => $row['form_id'], 'id' => $row['id']])) ?>"><?= $escape($row['name']) ?></a></th><td><?= (int) $row['id'] ?></td><td><?= $escape($row['state']) ?></td><td><?= $escape($row['received_at']) ?></td></tr><?php endforeach ?>
  </tbody></table></div>
  <div class="nfs-admin-table"><table class="table"><caption><?= $label('modified') ?></caption><thead><tr><th scope="col"><?= Text::_('COM_NICODE_FORM_STUDIO_NAME') ?></th><th scope="col">ID</th><th scope="col"><?= Text::_('COM_NICODE_FORM_STUDIO_STATE') ?></th><th scope="col"><?= $label('modified_at') ?></th></tr></thead><tbody>
  <?php foreach ($report['modified'] as $row): ?><tr><th scope="row"><?php if ($row['can_edit']): ?><a href="<?= $escape($url('editor', ['id' => $row['id']])) ?>"><?= $escape($row['name']) ?></a><?php else: ?><?= $escape($row['name']) ?><?php endif ?></th><td><?= (int) $row['id'] ?></td><td><?= $escape($row['state']) ?></td><td><?= $escape($row['modified_at']) ?></td></tr><?php endforeach ?>
  </tbody></table></div>
</section>
