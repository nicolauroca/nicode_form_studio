<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Contract;

use Nicode\FormStudio\Storage\StoredFile;

interface StorageProviderInterface extends ProviderInterface
{
    /** @param resource $stream */
    public function put($stream, int $maxBytes): StoredFile;
    /** @return resource */
    public function open(string $key);
    public function delete(string $key): void;
    public function exists(string $key): bool;
}
