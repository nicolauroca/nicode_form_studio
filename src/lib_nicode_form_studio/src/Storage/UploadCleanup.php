<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Storage;
use Nicode\FormStudio\Contract\StorageProviderInterface;

/** Discard a server-owned staged object, retaining its key in the outbox on failure. */
final class UploadCleanup
{
    public static function discard(StorageProviderInterface $storage, string $key, ?\Closure $queue = null): bool
    {
        try { $storage->delete($key); return true; }
        catch (\Throwable $error) {
            if ($queue === null) { throw $error; }
            $queue($storage->id(), $key);
            return false;
        }
    }
}
