<?php
declare(strict_types=1);
(static function () use ($connection, $compiler, $submissions, $argv, $root): void {
    $engine = $argv[1] ?? 'mysql';
    $forms = new Nicode\FormStudio\Infrastructure\Database\FormRepository($connection, $compiler);
    foreach (['complete', 'cleanup'] as $first) {
        $form = $forms->create('Concurrent expiry fixture', 'concurrent-expiry-' . bin2hex(random_bytes(5)), 1); $draft = $forms->draft($form); $field = Nicode\FormStudio\Domain\Uuid::create();
        $draft['elements'] = [['uuid' => $field, 'type' => 'field']]; $draft['fields'] = [['uuid' => $field, 'name' => 'answer', 'type' => 'text']]; $draft['persistence']['mode'] = 'none';
        $revision = $forms->saveDraft($form, 0, $draft, 1); $version = $forms->publish($form, $revision, 1); $hash = hash('sha256', random_bytes(32));
        $stored = $submissions->persist($form, $version, $forms->version($form, $version), [$field => 'race private value'], $hash);
        $expiry = '2001-01-01 00:00:00'; $connection->execute('UPDATE ' . $connection->table('attempts') . ' SET expires_at=:date WHERE form_id=:form', [':date' => $expiry, ':form' => $form]);
        $attempt = (int) $connection->row('SELECT id FROM ' . $connection->table('attempts') . ' WHERE form_id=:form', [':form' => $form])['id'];
        $key = bin2hex(random_bytes(32)); $path = $root . '/build/attempt-race-' . $key . '.json';
        file_put_contents($path, json_encode(['form' => $form, 'submission' => $stored->id, 'hash' => $hash, 'attempt' => $attempt, 'expiry' => $expiry], JSON_THROW_ON_ERROR));
        $workers = [];
        $expect = static function ($pipe, string $expected): void { $line = fgets($pipe); if (trim((string) $line) !== $expected) { throw new RuntimeException('Concurrent attempt worker protocol failed: expected ' . $expected); } };
        try {
            foreach ([$first, $first === 'complete' ? 'cleanup' : 'complete'] as $index => $mode) {
                $command = [PHP_BINARY]; if ($engine === 'postgresql') { array_push($command, '-d', 'extension=pgsql', '-d', 'extension=pdo_pgsql'); }
                array_push($command, __DIR__ . '/attempt-race-worker.php', $engine, $key, $mode, $index === 0 ? 'hold' : 'run');
                $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $root . '/build/attempt-race-' . $key . '-' . $index . '.log', 'w']], $pipes, $root);
                if (!is_resource($process)) { throw new RuntimeException('Unable to start attempt race worker.'); }
                stream_set_timeout($pipes[1], 15); $workers[] = [$process, $pipes]; $expect($pipes[1], 'ready');
            }
            fwrite($workers[0][1][0], "go\n"); fflush($workers[0][1][0]); $expect($workers[0][1][1], 'started'); $expect($workers[0][1][1], 'locked');
            fwrite($workers[1][1][0], "go\n"); fflush($workers[1][1][0]); $expect($workers[1][1][1], 'started');
            // Both processes are live; the first holds the actual database lock.
            fwrite($workers[0][1][0], "release\n"); fflush($workers[0][1][0]);
            foreach ($workers as [$process, $pipes]) { $expect($pipes[1], 'done'); }
            $remainingResponse = $connection->row('SELECT id FROM ' . $connection->table('submissions') . ' WHERE id=:id', [':id' => $stored->id]);
            $remainingAttempt = $connection->row('SELECT id,state FROM ' . $connection->table('attempts') . ' WHERE id=:id', [':id' => $attempt]);
            if ($remainingResponse !== null || $remainingAttempt !== null) { throw new RuntimeException('Concurrent completion/expiry left data: ' . $first . ' ' . json_encode([$remainingResponse, $remainingAttempt])); }
        } finally {
            foreach ($workers as [$process, $pipes]) { foreach ($pipes as $pipe) { fclose($pipe); } if (proc_get_status($process)['running']) { proc_terminate($process); } proc_close($process); }
            unlink($path);
        }
    }
    echo "Concurrent attempt cleanup/completion: two processes, both lock orderings, no deadlock or surviving no-store response/receipt passed.\n";
})();
