<?php
declare(strict_types=1);
defined('_JEXEC') or die;
?>
<div class="com-nicode-form-studio">
  <?php if ($this->form['title'] !== '') : ?><h1><?= $this->escape($this->form['title']) ?></h1><?php endif; ?>
  <?php if (isset($this->form['result'])) : ?>
    <div role="status" tabindex="-1" class="nfs-confirmation">
      <?php if (($this->form['result']['heading'] ?? '') !== '') : ?><h2><?= $this->escape($this->form['result']['heading']) ?></h2><?php endif; ?>
      <p><?= $this->escape($this->form['result']['message']) ?></p>
      <?php if (isset($this->form['result']['reference'])) : ?><p><?= $this->escape($this->form['result']['reference']) ?></p><?php endif; ?>
      <?php if (!empty($this->form['result']['summary'])) : ?>
        <dl class="nfs-confirmation-summary">
          <?php foreach ($this->form['result']['summary'] as $item) : ?><dt><?= $this->escape($item['label']) ?></dt><dd><?= $this->escape($item['value']) ?></dd><?php endforeach; ?>
        </dl>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?= $this->form['html'] ?>
</div>
