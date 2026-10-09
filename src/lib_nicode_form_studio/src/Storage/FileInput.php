<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Storage;
/** Extracts the expected UUID file controls from PHP's multipart shape. */
final class FileInput
{
    public static function extract(array $phpFiles, array $allowedFields): array
    {
        $result = [];
        foreach ($allowedFields as $uuid) {
            if (!isset($phpFiles['error'][$uuid])) { continue; }
            $errors = $phpFiles['error'][$uuid]; $multiple = is_array($errors); $items = $multiple ? $errors : [$errors];
            if (!array_is_list($items)) { throw new \InvalidArgumentException('Malformed multipart file list.'); }
            foreach ($items as $i => $error) {
                if ($error === UPLOAD_ERR_NO_FILE) { continue; }
                if (!is_int($error)) { throw new \InvalidArgumentException('Malformed upload error.'); }
                $entry = ['error' => $error];
                foreach (['name', 'tmp_name'] as $key) {
                    $value = $multiple ? ($phpFiles[$key][$uuid][$i] ?? null) : ($phpFiles[$key][$uuid] ?? null);
                    if (!is_string($value)) { throw new \InvalidArgumentException('Malformed multipart file metadata.'); }
                    $entry[$key] = $value;
                }
                $result[$uuid][] = $entry;
            }
        }
        return $result;
    }
}
