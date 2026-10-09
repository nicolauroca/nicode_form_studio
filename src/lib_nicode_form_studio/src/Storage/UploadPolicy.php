<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Storage;

final readonly class UploadPolicy
{
    public function __construct(public array $extensions, public array $mimeTypes, public int $maxBytes, public int $maxFiles = 1)
    {
        if ($extensions === [] || $mimeTypes === [] || $maxBytes < 1 || $maxFiles < 1) { throw new \InvalidArgumentException('Upload policy must explicitly allow extensions, MIME types and positive limits.'); }
        foreach ($extensions as $extension) {
            if (!is_string($extension) || preg_match('/^[a-z0-9]+$/D', $extension) !== 1 || in_array($extension, ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'html', 'htm', 'svg', 'js', 'exe', 'dll', 'bat', 'cmd', 'ps1', 'com', 'scr', 'htaccess'], true)) { throw new \InvalidArgumentException('Executable or active-content extension is not allowed.'); }
        }
        foreach ($mimeTypes as $mime) {
            if (!is_string($mime) || preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~D', $mime) !== 1) { throw new \InvalidArgumentException('Invalid MIME allowlist.'); }
        }
    }
}
