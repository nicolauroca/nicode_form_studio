<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Storage;

use Nicode\FormStudio\Contract\StagedStorageProviderInterface;

/** Opaque storage keys; no original filename is ever used as a filesystem path. */
final readonly class LocalStorage implements StagedStorageProviderInterface
{
    private string $root;
    public function __construct(string $root, string $publicRoot, private ?\Closure $queueCleanup = null)
    {
        if ($root === '' || $publicRoot === '') { throw new \InvalidArgumentException('Storage roots must be explicit.'); }
        $root = realpath($root); $publicRoot = realpath($publicRoot);
        if ($root === false || $publicRoot === false || !is_dir($root) || !is_writable($root)) { throw new \InvalidArgumentException('Storage and public roots must exist; storage must be writable.'); }
        $normalize = static fn (string $path): string => (PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $path)) : $path) . '/';
        if (str_starts_with($normalize($root), $normalize($publicRoot))) { throw new \InvalidArgumentException('Storage must be outside the public root.'); }
        $this->root = $root;
    }
    public function id(): string { return 'local'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['id' => 'local', 'version' => $this->version(), 'private' => true, 'streaming' => true]; }
    public function validateConfiguration(array $configuration, string $path): array { return []; }

    public function put($stream, int $maxBytes): StoredFile
    {
        return $this->putReserved($this->reserveKey(), $stream, $maxBytes);
    }
    public function reserveKey(): string { return bin2hex(random_bytes(32)); }
    public function putReserved(string $key, mixed $stream, int $maxBytes): StoredFile
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream' || $maxBytes < 0) { throw new \InvalidArgumentException('A readable stream and non-negative byte limit are required.'); }
        $path = $this->path($key);
        $output = @fopen($path, 'xb');
        if ($output === false) {
            if (file_exists($path) || is_link($path)) { throw new StorageCollision('Reserved storage key already exists.'); }
            throw new \RuntimeException('Private storage is unavailable.');
        }
        $hash = hash_init('sha256'); $size = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) { throw new \RuntimeException('Input stream read failed.'); }
                if ($chunk === '' && !feof($stream)) { throw new \RuntimeException('Input stream stalled.'); }
                $size += strlen($chunk);
                if ($size > $maxBytes) { throw new \LengthException('Storage byte limit exceeded.'); }
                hash_update($hash, $chunk);
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = fwrite($output, substr($chunk, $offset));
                    if ($written === false || $written === 0) { throw new \RuntimeException('Private storage write failed.'); }
                    $offset += $written;
                }
            }
            if (!fflush($output)) { throw new \RuntimeException('Private storage flush failed.'); }
            fclose($output);
            return new StoredFile($this->id(), $key, $size, hash_final($hash));
        } catch (\Throwable $error) {
            fclose($output);
            UploadCleanup::discard($this, $key, $this->queueCleanup);
            throw $error;
        }
    }
    public function open(string $key)
    {
        $path = $this->path($key);
        if (is_link($path)) { throw new \DomainException('Symlink storage objects are not allowed.'); }
        // Missing/removed objects are expected failures; do not expose private paths.
        $stream = @fopen($path, 'rb');
        if ($stream === false) { throw new \OutOfBoundsException('Stored object not found.'); }
        return $stream;
    }
    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_link($path)) { throw new \DomainException('Symlink storage objects are not allowed.'); }
        if (is_file($path) && !unlink($path)) { throw new \RuntimeException('Stored object could not be removed.'); }
    }
    public function exists(string $key): bool
    {
        $path = $this->path($key);
        if (is_link($path)) { throw new \DomainException('Symlink storage objects are not allowed.'); }
        return is_file($path);
    }
    private function path(string $key): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) { throw new \InvalidArgumentException('Invalid storage key.'); }
        return $this->root . DIRECTORY_SEPARATOR . $key;
    }
}
