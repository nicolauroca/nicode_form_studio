<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Storage;

/** Inspects bytes and filename. HTTP provenance is a separate mandatory gateway. */
final class UploadInspector
{
    public function inspect(string $path, string $originalName, UploadPolicy $policy): array
    {
        if (!is_file($path) || is_link($path)) { throw new \InvalidArgumentException('Upload file is unavailable.'); }
        if (!mb_check_encoding($originalName, 'UTF-8') || preg_match('/[\x00-\x1f\x7f\\\\\/:]/u', $originalName) || str_ends_with($originalName, '.') || str_ends_with($originalName, ' ')) {
            throw new \InvalidArgumentException('Unsafe upload filename.');
        }
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, $policy->extensions, true)) { throw new \InvalidArgumentException('Upload extension is not allowed.'); }
        $size = filesize($path);
        if ($size === false || $size > $policy->maxBytes) { throw new \LengthException('Upload size limit exceeded.'); }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($mime === false || !in_array($mime, $policy->mimeTypes, true)) { throw new \InvalidArgumentException('Upload content MIME type is not allowed.'); }
        return ['original_name' => mb_substr($originalName, 0, 255), 'mime' => $mime, 'size' => $size];
    }
}
