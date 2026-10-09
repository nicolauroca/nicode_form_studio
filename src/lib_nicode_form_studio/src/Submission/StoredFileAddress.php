<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Submission;

use Nicode\FormStudio\Domain\FieldAddress;

/** Binds a stored attachment to its exact canonical answer scope. */
final class StoredFileAddress
{
    public static function key(array $file): string
    {
        $path = $file['instance_path'];
        if (!is_string($path) || !is_string($file['instance_hash']) || !hash_equals(hash('sha256', $path), $file['instance_hash'])) { throw new \InvalidArgumentException('Invalid stored file scope.'); }
        return FieldAddress::fromKey(($path === '' ? '' : $path . '/') . $file['field_uuid'])->key();
    }

    public static function matches(array $file, array $values): bool
    {
        $key = self::key($file); $value = $values[$key] ?? null;
        if (!is_array($value)) { return false; }
        $receipts = array_is_list($value) ? $value : [$value];
        foreach ($receipts as $receipt) {
            if (is_array($receipt) && ($receipt['uuid'] ?? null) === $file['uuid']) { return true; }
        }
        return false;
    }
}
