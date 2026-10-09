<?php
declare(strict_types=1);
(static function () use ($connection, $compiler, $submissions, $jobs, $runs): void {
    $forms = new Nicode\FormStudio\Infrastructure\Database\FormRepository($connection, $compiler); $cases = [];
    foreach ([['none', 'expired'], ['none', 'fresh'], ['none', 'live'], ['full', 'expired'], ['metadata', 'expired'], ['none', 'interrupted']] as [$mode, $kind]) {
        $form = $forms->create('Attempt expiry fixture', 'attempt-expiry-' . bin2hex(random_bytes(6)), 1); $draft = $forms->draft($form);
        $field = Nicode\FormStudio\Domain\Uuid::create(); $draft['elements'] = [['uuid' => $field, 'type' => 'field']]; $draft['fields'] = [['uuid' => $field, 'name' => 'answer', 'type' => 'text']]; $draft['persistence']['mode'] = $mode;
        $revision = $forms->saveDraft($form, 0, $draft, 1); $version = $forms->publish($form, $revision, 1);
        $response = $submissions->persist($form, $version, $forms->version($form, $version), [$field => 'private expiry fixture'], hash('sha256', random_bytes(32)));
        if ($kind !== 'fresh') { $connection->execute('UPDATE ' . $connection->table('attempts') . ' SET expires_at=:date WHERE form_id=:form', [':date' => '2000-01-01 00:00:00', ':form' => $form]); }
        $lease = null;
        if (in_array($kind, ['live', 'interrupted'], true)) {
            $lease = $runs->claim($response->id, Nicode\FormStudio\Domain\Uuid::create(), 'fixture');
            if ($kind === 'interrupted') { $connection->execute('UPDATE ' . $connection->table('action_runs') . ' SET lease_until=:date WHERE id=:id', [':date' => '2000-01-01 00:00:00', ':id' => $lease->id]); }
        }
        $cases[] = [$form, $response->id, $mode, $kind, $submissions->get($form, $response->id), $lease];
    }
    $handler = new Nicode\FormStudio\Jobs\AttemptCleanupHandler($connection, $forms, $jobs);
    $leaseFor = static fn (array $cursor) => new Nicode\FormStudio\Jobs\JobLease(1, Nicode\FormStudio\Domain\Uuid::create(), 'attempt-cleanup', 0, [], $cursor, str_repeat('c', 64), 1, 0, 0);
    $start = ['cutoff' => '2000-01-02 00:00:00', 'expires_at' => '1999-12-31 00:00:00', 'id' => 0];
    try { $connection->transaction(function () use ($handler, $leaseFor, $start): void { $handler->run($leaseFor($start), 2); throw new RuntimeException('Expected rollback'); }); } catch (RuntimeException $error) { if ($error->getMessage() !== 'Expected rollback') { throw $error; } }
    foreach ($cases as [$form, $id, $mode, $kind, $before]) { if ($submissions->get($form, $id) !== $before) { throw new RuntimeException('Attempt cleanup escaped transaction rollback.'); } }
    $cursor = $start; $complete = false;
    for ($batch = 0; $batch < 30 && !$complete; $batch++) {
        $progress = $connection->transaction(fn () => $handler->run($leaseFor($cursor), 2));
        if ($progress->processed > 2) { throw new RuntimeException('Attempt cleanup exceeded its batch limit.'); }
        $cursor = $progress->cursor; $complete = $progress->complete;
    }
    if (!$complete) { throw new RuntimeException('Skipped live action prevented cleanup completion.'); }
    foreach ($cases as [$form, $id, $mode, $kind, $before, $actionLease]) {
        $row = $connection->row('SELECT * FROM ' . $connection->table('submissions') . ' WHERE id=:id', [':id' => $id]);
        $retained = $mode !== 'none' || in_array($kind, ['fresh', 'live'], true);
        if (($retained && $row !== $before) || (!$retained && $row !== null)) { throw new RuntimeException('Attempt expiry violated response storage policy.'); }
        $attempt = $connection->row('SELECT id FROM ' . $connection->table('attempts') . ' WHERE form_id=:form', [':form' => $form]);
        if (($attempt !== null) !== in_array($kind, ['fresh', 'live'], true)) { throw new RuntimeException('Attempt expiry removed active replay data or retained expired receipt.'); }
        if ($kind === 'interrupted') {
            try { $runs->finish($actionLease, 'succeeded', 'late_worker'); throw new LogicException('Erased action accepted stale completion.'); } catch (DomainException) {}
        }
    }
    echo "Attempt cleanup: bounded cursor, rollback, live/fresh protection, historical none erasure, full/metadata preservation and stale-worker fencing passed.\n";
})();
