<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Contract;

use Nicode\FormStudio\Storage\StoredFile;

/** Managed upload capability: reserve does not create bytes; write never replaces a key. */
interface StagedStorageProviderInterface extends StorageProviderInterface
{
    public function reserveKey(): string;
    public function putReserved(string $key, mixed $stream, int $maxBytes): StoredFile;
}
