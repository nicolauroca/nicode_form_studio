<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Storage;

use Nicode\FormStudio\Contract\StorageProviderInterface;

/** Keep durable cleanup retryable while the configured local volume is absent. */
final readonly class UnavailableStorage implements StorageProviderInterface
{
    public function id(): string { return 'local'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['private' => true, 'available' => false]; }
    public function validateConfiguration(array $configuration, string $path): array { return []; }
    public function put($stream, int $maxBytes): StoredFile { throw new \RuntimeException('Private storage unavailable.'); }
    public function open(string $key) { throw new \RuntimeException('Private storage unavailable.'); }
    public function delete(string $key): void { throw new \RuntimeException('Private storage unavailable.'); }
    public function exists(string $key): bool { throw new \RuntimeException('Private storage unavailable.'); }
}
