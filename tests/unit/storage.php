<?php
declare(strict_types=1);

use Nicode\FormStudio\Storage\LocalStorage;
use Nicode\FormStudio\Storage\UploadInspector;
use Nicode\FormStudio\Storage\UploadPolicy;
use Nicode\FormStudio\Storage\HttpUploadGateway;

test('staged upload cleanup queues only its owned key and exposes an unavailable outbox', function (): void {
    $storage = new class implements \Nicode\FormStudio\Contract\StorageProviderInterface {
        public bool $available = false;
        public function id(): string { return 'fixture'; }
        public function version(): string { return '1.0.0'; }
        public function metadata(): array { return []; }
        public function validateConfiguration(array $configuration, string $path): array { return []; }
        public function put($stream, int $maxBytes): \Nicode\FormStudio\Storage\StoredFile { throw new LogicException(); }
        public function open(string $key) { throw new LogicException(); }
        public function exists(string $key): bool { return true; }
        public function delete(string $key): void { if (!$this->available) { throw new RuntimeException('storage unavailable'); } }
    };
    $queued = []; $enqueue = static function (string $provider, string $key) use (&$queued): void { $queued[] = [$provider, $key]; };
    same(false, \Nicode\FormStudio\Storage\UploadCleanup::discard($storage, 'opaque-owned-key', $enqueue));
    same([['fixture', 'opaque-owned-key']], $queued);
    raises(RuntimeException::class, static fn () => \Nicode\FormStudio\Storage\UploadCleanup::discard($storage, 'opaque-owned-key', static fn () => throw new RuntimeException('outbox unavailable')));
    $storage->available = true;
    same(true, \Nicode\FormStudio\Storage\UploadCleanup::discard($storage, 'opaque-owned-key', $enqueue));
    same(1, count($queued));
});

test('private storage streams bytes and blocks public roots and traversal', function (): void {
    $root = dirname(__DIR__) . '/artifacts';
    $storage = new LocalStorage($root, dirname(__DIR__, 2) . '/src');
    raises(InvalidArgumentException::class, fn () => new LocalStorage($root, dirname(__DIR__)));
    $stream = fopen('php://temp', 'w+b'); fwrite($stream, 'private data'); rewind($stream);
    $object = $storage->put($stream, 100); same(12, $object->size); same(hash('sha256', 'private data'), $object->checksum);
    $read = $storage->open($object->key); same('private data', stream_get_contents($read)); fclose($read);
    raises(InvalidArgumentException::class, fn () => $storage->open('../secret'));
    $storage->delete($object->key); same(false, $storage->exists($object->key));
    rewind($stream); raises(LengthException::class, fn () => $storage->put($stream, 2)); fclose($stream);
});
test('upload inspector validates bytes not browser MIME and gateway verifies provenance', function (): void {
    $root = dirname(__DIR__) . '/artifacts'; $file = $root . '/upload-test.txt'; file_put_contents($file, 'plain text content');
    try {
        $inspector = new UploadInspector(); $policy = new UploadPolicy(['txt'], ['text/plain'], 100);
        same('text/plain', $inspector->inspect($file, 'safe.txt', $policy)['mime']);
        raises(InvalidArgumentException::class, fn () => $inspector->inspect($file, '../unsafe.txt', $policy));
        raises(InvalidArgumentException::class, fn () => $inspector->inspect($file, 'image.png', new UploadPolicy(['png'], ['image/png'], 100)));
        raises(InvalidArgumentException::class, fn () => new UploadPolicy(['php'], ['text/plain'], 100));
        $gateway = new HttpUploadGateway($inspector, new LocalStorage($root, dirname(__DIR__, 2) . '/src'));
        raises(InvalidArgumentException::class, fn () => $gateway->receive([['name' => 'safe.txt', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK]], $policy));
    } finally { unlink($file); }
});
