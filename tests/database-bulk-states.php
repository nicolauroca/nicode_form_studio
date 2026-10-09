<?php
declare(strict_types=1);

(static function () use ($connection, $forms, $jobs, $search, $maintenance, $submissions, $bulkForm, $bulkVersion, $bulkSpec, $bulkField, $atomicWorker): void {
    $states = Nicode\FormStudio\Application\SubmissionAdministration::STATES;
    $administration = new Nicode\FormStudio\Application\SubmissionAdministration($connection, static fn (): bool => true);
    $audit = static fn (string $uuid): array => $connection->rows('SELECT actor_id, form_id, safe_metadata FROM ' . $connection->table('audit_log') . " WHERE submission_uuid = :uuid AND event_type = 'submission.state' ORDER BY id", [':uuid' => $uuid]);
    $parameters = static fn (string $token, string $state): array => [
        'form_id' => $bulkForm, 'version_id' => $bulkVersion, 'operation' => 'state', 'state' => $state,
        'query' => ['sort' => 'received_at_asc', 'filters' => ['form_id' => $bulkForm], 'fields' => [['field' => $bulkField, 'operator' => 'equals', 'value' => $token]]],
    ];
    foreach ($states as $to) {
        $token = 'bulk-state-' . bin2hex(random_bytes(8));
        $responses = [];
        foreach ($states as $from) {
            $response = $submissions->persist($bulkForm, $bulkVersion, $bulkSpec, [$bulkField => $token], hash('sha256', random_bytes(32)));
            $administration->changeState(1, $bulkForm, $response->id, 'new', $from);
            $connection->execute('UPDATE ' . $connection->table('submissions') . " SET action_status = 'partial_failure' WHERE id = :id", [':id' => $response->id]);
            $responses[] = [$response, $submissions->get($bulkForm, $response->id), $audit($response->uuid)];
        }
        $excluded = $submissions->persist($bulkForm, $bulkVersion, $bulkSpec, [$bulkField => 'excluded-' . $token], hash('sha256', random_bytes(32)));
        $excludedBefore = $submissions->get($bulkForm, $excluded->id);
        $job = $jobs->enqueue('submission-bulk', $parameters($token, $to), 1);
        $atomicWorker->tick(2);
        if ((int) $jobs->get($job)['processed'] !== 2 || $jobs->get($job)['state'] !== 'pending') { throw new RuntimeException('Bulk state chunk did not preserve its checkpoint.'); }
        $late = $submissions->persist($bulkForm, $bulkVersion, $bulkSpec, [$bulkField => $token], hash('sha256', random_bytes(32)));
        $lateBefore = $submissions->get($bulkForm, $late->id);
        for ($chunk = 0; $chunk < 5 && $jobs->get($job)['state'] !== 'completed'; $chunk++) { $atomicWorker->tick(2); }
        $completed = $jobs->get($job);
        if ($completed['state'] !== 'completed' || (int) $completed['processed'] !== count($states) || (int) $completed['failed'] !== 0) { throw new RuntimeException('Bulk state matrix skipped or duplicated a response across chunks.'); }
        foreach ($responses as [$response, $before, $events]) {
            $expected = $before; $expected['state'] = $to;
            $afterEvents = $audit($response->uuid);
            $changed = $before['state'] !== $to;
            if ($submissions->get($bulkForm, $response->id) !== $expected || count($afterEvents) !== count($events) + (int) $changed) { throw new RuntimeException('Bulk transition changed answers/action status/history or duplicated its audit.'); }
            if ($changed) {
                $event = end($afterEvents);
                if ((int) $event['actor_id'] !== 1 || (int) $event['form_id'] !== $bulkForm || json_decode($event['safe_metadata'], true, flags: JSON_THROW_ON_ERROR) !== ['from' => $before['state'], 'to' => $to]) { throw new RuntimeException('Bulk state audit lost transition or ownership.'); }
            }
        }
        foreach ([[$excluded, $excludedBefore], [$late, $lateBefore]] as [$response, $before]) {
            if ($submissions->get($bulkForm, $response->id) !== $before || $audit($response->uuid) !== []) { throw new RuntimeException('Bulk state operation changed a non-match or late response.'); }
        }
    }

    foreach (['core.manage', 'formstudio.submissions.view', 'formstudio.submissions.manage'] as $revoked) {
        $denied = null;
        $permission = static function (int $actor, ?int $form, string $permission) use (&$denied): bool { return $actor === 1 && $permission !== $denied; };
        $handlers = new Nicode\FormStudio\Registry\JobHandlerRegistry();
        $handlers->register(new Nicode\FormStudio\Jobs\BulkSubmissionHandler($connection, $forms, $jobs, $search, new Nicode\FormStudio\Application\SubmissionAdministration($connection, $permission), $maintenance, $permission));
        $worker = new Nicode\FormStudio\Jobs\JobWorker($jobs, $handlers, $connection);
        $token = 'bulk-revocation-' . bin2hex(random_bytes(8)); $responses = [];
        for ($i = 0; $i < 3; $i++) { $responses[] = $submissions->persist($bulkForm, $bulkVersion, $bulkSpec, [$bulkField => $token], hash('sha256', random_bytes(32))); }
        $job = $jobs->enqueue('submission-bulk', $parameters($token, 'processed'), 1);
        $worker->tick(1);
        if ((int) $jobs->get($job)['processed'] !== 1) { throw new RuntimeException('Revocation fixture did not commit its first chunk.'); }
        $before = array_map(static fn ($response): array => [$submissions->get($bulkForm, $response->id), $audit($response->uuid)], $responses);
        $denied = $revoked;
        try { $worker->tick(1); throw new LogicException('Bulk job continued after permission revocation.'); } catch (DomainException) {}
        $failed = $jobs->get($job);
        if ($failed['state'] !== 'failed' || (int) $failed['processed'] !== 1 || $failed['result_code'] !== 'handler_failed') { throw new RuntimeException('Revoked bulk job lost its committed progress or failure boundary.'); }
        foreach ($responses as $index => $response) {
            if ([$submissions->get($bulkForm, $response->id), $audit($response->uuid)] !== $before[$index]) { throw new RuntimeException('Revoked bulk chunk changed a response or audit.'); }
        }
    }
    echo "Bulk state matrix: 49 transitions across bounded chunks, no-op audit, payload/action isolation, non-match/late exclusion and three permission revocations between chunks verified.\n";
})();
