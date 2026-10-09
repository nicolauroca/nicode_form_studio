<?php
declare(strict_types=1);

(static function () use ($connection, $privateStorage): void {
    $provider = new class($privateStorage) implements Nicode\FormStudio\Contract\StorageProviderInterface {
        public ?string $failKey = null;
        public array $deleted = [];
        public function __construct(private Nicode\FormStudio\Contract\StorageProviderInterface $delegate) {}
        public function id(): string { return 'fixture.cleanup'; }
        public function version(): string { return '1.0.0'; }
        public function metadata(): array { return []; }
        public function validateConfiguration(array $configuration, string $path): array { return []; }
        public function put($stream, int $maxBytes): Nicode\FormStudio\Storage\StoredFile { return $this->delegate->put($stream, $maxBytes); }
        public function open(string $key) { return $this->delegate->open($key); }
        public function exists(string $key): bool { return $this->delegate->exists($key); }
        public function delete(string $key): void {
            if ($key === $this->failKey) { throw new RuntimeException('Private storage failure /secret/path'); }
            $this->delegate->delete($key); $this->deleted[] = $key;
        }
    };
    $keys = [];
    try {
        for ($i = 0; $i < 3; $i++) {
            $stream = fopen('php://temp', 'w+b'); fwrite($stream, 'Cleanup recovery ' . $i); rewind($stream);
            try { $keys[] = $privateStorage->put($stream, 100)->key; } finally { fclose($stream); }
        }
        $now = time();
        $jobs = new Nicode\FormStudio\Infrastructure\Database\JobRepository($connection, static function () use (&$now): int { return $now; });
        $storage = new Nicode\FormStudio\Registry\StorageProviderRegistry(); $storage->register($provider);
        $handlers = new Nicode\FormStudio\Registry\JobHandlerRegistry();
        $handlers->register(new Nicode\FormStudio\Jobs\FileCleanupHandler($storage, $jobs));
        $worker = new Nicode\FormStudio\Jobs\JobWorker($jobs, $handlers);
        $parameters = ['objects' => array_map(static fn (string $key): array => ['provider' => $provider->id(), 'storage_key' => $key], array_slice($keys, 0, 2))];
        $id = $jobs->enqueue('file-cleanup', $parameters, 1);
        $provider->failKey = $keys[1]; $worker->tick(2);
        $failed = $jobs->get($id);
        if ($failed['state'] !== 'retryable' || $failed['result_code'] !== 'file_cleanup_unavailable' || (int) $failed['processed'] !== 0 || json_decode($failed['cursor_data'], true) !== [] || json_decode($failed['parameters'], true) !== $parameters || str_contains(json_encode($failed), '/secret/path')) { throw new RuntimeException('Partial cleanup lost retry ownership or exposed provider error details.'); }
        if ($privateStorage->exists($keys[0]) || !$privateStorage->exists($keys[1]) || !$privateStorage->exists($keys[2])) { throw new RuntimeException('Partial cleanup removed the wrong objects.'); }
        if ($worker->tick(2)) { throw new RuntimeException('Cleanup retried before its backoff elapsed.'); }
        $provider->failKey = null; $now += 61; $worker->tick(2);
        $completed = $jobs->get($id);
        if ($completed['state'] !== 'completed' || (int) $completed['processed'] !== 2 || json_decode($completed['cursor_data'], true) !== ['offset' => 2] || $provider->deleted !== array_slice($keys, 0, 2) || $privateStorage->exists($keys[1]) || !$privateStorage->exists($keys[2])) { throw new RuntimeException('Cleanup retry did not safely resume after partial external effects.'); }
        echo "File cleanup recovery: partial physical deletion, durable retry/backoff, no private error detail, idempotent replay and unrelated object preservation passed.\n";
    } finally { foreach ($keys as $key) { $privateStorage->delete($key); } }
})();
