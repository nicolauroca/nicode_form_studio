<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Storage;

/** Server-only capability; never serialize this token into a public file receipt. */
final readonly class UploadReservation
{
    public function __construct(public int $id, public int $form, public string $provider, public string $key, public string $token) {}
}
