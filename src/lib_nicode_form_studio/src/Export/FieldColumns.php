<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Export;

use Nicode\FormStudio\Domain\FieldAddress;

/** Project already-authorized reader values into stable definition-field columns. */
final class FieldColumns
{
    public static function project(array $values, array $fields): array
    {
        $columns = array_fill_keys($fields, null); $scoped = []; $ordinary = [];
        foreach ($values as $key => $value) {
            $address = FieldAddress::fromKey($key);
            if (!array_key_exists($address->field, $columns)) { continue; }
            $field = $address->field;
            if ($address->instances === []) {
                if (isset($scoped[$field])) { throw new \InvalidArgumentException('Mixed field scopes in export.'); }
                $ordinary[$field] = true; $columns[$field] = $value;
            } else {
                if (isset($ordinary[$field])) { throw new \InvalidArgumentException('Mixed field scopes in export.'); }
                if (!isset($scoped[$field])) { $scoped[$field] = true; $columns[$field] = []; }
                // Reader iteration follows stored row order, including nested scopes.
                $columns[$field][] = ['instance_path' => substr($key, 0, -37), 'value' => $value];
            }
        }
        return $columns;
    }
}
