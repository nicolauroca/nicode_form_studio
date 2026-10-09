<?php
declare(strict_types=1);
defined('_JEXEC') or die;
if ($form === null) { return; }
$escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$classes = array_filter(preg_split('/\s+/', (string) $params->get('form_class', ''), -1, PREG_SPLIT_NO_EMPTY), static fn (string $value): bool => preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/D', $value) === 1);
?>
<div class="nfs-module<?= $classes ? ' ' . $escape(implode(' ', array_slice(array_unique($classes), 0, 10))) : '' ?>" data-nfs-module="<?= (int) $module->id ?>">
<?php if ($params->get('show_form_title', 0) && isset($form['title'])): ?>
  <h2><?= $escape($form['title']) ?></h2>
<?php endif; ?>
<?php if ($params->get('show_description', 0) && ($form['description'] ?? '') !== ''): ?>
  <p class="nfs-module-description"><?= $escape($form['description']) ?></p>
<?php endif; ?>
<?= $form['html'] ?>
</div>
