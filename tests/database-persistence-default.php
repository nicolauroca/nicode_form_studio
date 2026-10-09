<?php
declare(strict_types=1);
(static function () use ($connection, $compiler): void {
    foreach (['full', 'metadata', 'none'] as $mode) {
        $repository = new Nicode\FormStudio\Infrastructure\Database\FormRepository($connection, $compiler, $mode);
        $form = $repository->create('Persistence default', 'default-' . bin2hex(random_bytes(6)), 1);
        $draft = $repository->draft($form);
        if ($draft['persistence']['mode'] !== $mode || (int) $repository->get($form)['draft_revision'] !== 0) { throw new RuntimeException('New form did not snapshot its configured persistence default.'); }
        $field = Nicode\FormStudio\Domain\Uuid::create();
        $draft['elements'] = [['uuid' => $field, 'type' => 'field']]; $draft['fields'] = [['uuid' => $field, 'name' => 'answer', 'type' => 'text']];
        $revision = $repository->saveDraft($form, 0, $draft, 1); $version = $repository->publish($form, $revision, 1);
        $other = new Nicode\FormStudio\Infrastructure\Database\FormRepository($connection, $compiler, $mode === 'full' ? 'none' : 'full');
        if ($other->draft($form)['persistence']['mode'] !== $mode || $other->version($form, $version)->toArray()['persistence']['mode'] !== $mode) { throw new RuntimeException('Changing global default changed an existing draft or snapshot.'); }
        $draft['persistence']['mode'] = $mode === 'none' ? 'metadata' : 'none';
        $revision = $other->saveDraft($form, (int) $other->get($form)['draft_revision'], $draft, 1); $next = $other->publish($form, $revision, 1);
        if ($other->version($form, $next)->toArray()['persistence']['mode'] !== $draft['persistence']['mode'] || $other->version($form, $version)->toArray()['persistence']['mode'] !== $mode) { throw new RuntimeException('Form override changed its historical policy.'); }
    }
    try { new Nicode\FormStudio\Infrastructure\Database\FormRepository($connection, $compiler, 'invalid'); throw new LogicException('Invalid global storage mode silently accepted.'); } catch (InvalidArgumentException) {}
    echo "Persistence defaults: three creation modes, stable drafts/snapshots after configuration changes, explicit per-form override and invalid-mode rejection passed.\n";
})();
