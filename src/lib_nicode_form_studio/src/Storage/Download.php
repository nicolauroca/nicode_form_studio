<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Storage;
final readonly class Download
{
    /** @param resource $stream Caller closes this stream after bounded delivery. */
    public function __construct(public mixed $stream, public array $headers) {}
}
