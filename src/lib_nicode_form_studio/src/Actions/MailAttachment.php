<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Actions;

/** Authorized bytes, never a filesystem path or a caller-provided storage key. */
final readonly class MailAttachment
{
    public const MAXIMUM_BYTES = 10485760;
    public function __construct(public string $name, public string $mimeType, public string $bytes)
    {
        if ($name === '' || strlen($name) > 255 || !mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $name) || in_array($name, ['.', '..'], true)) { throw new \InvalidArgumentException('Invalid attachment name.'); }
        if (strlen($mimeType) > 127 || preg_match('/^[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*$/D', $mimeType) !== 1) { throw new \InvalidArgumentException('Invalid attachment MIME type.'); }
        if (strlen($bytes) > self::MAXIMUM_BYTES) { throw new \LengthException('Attachment exceeds mail size limit.'); }
    }
}
