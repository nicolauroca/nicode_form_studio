<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Storage;

final readonly class StoredFile
{
    public function __construct(public string $provider, public string $key, public int $size, public string $checksum) {}
}
