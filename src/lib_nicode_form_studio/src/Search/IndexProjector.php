<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Search;

use Nicode\FormStudio\Domain\FormSpec;
use Nicode\FormStudio\Registry\FieldTypeRegistry;
use Nicode\FormStudio\Validation\Decimal;

final readonly class IndexProjector
{
    public function __construct(private FieldTypeRegistry $fields) {}

    /** Projection is derived only from canonical accepted values, never browser input. */
    public function project(FormSpec $spec, array $values): array
    {
        return $this->projectFields($spec->toArray()['fields'], $values);
    }

    /** Internal addressed projection; SQL storage must retain both scope and value ordinal. */
    public function projectInstances(FormSpec $spec, array $declarations, array $values, int $budget = 10000): array
    {
        $definition = $spec->toArray();
        $instances = new \Nicode\FormStudio\Domain\RepeatedInstances($definition['elements'], $declarations, $budget);
        $instances->bind($values, $budget);
        $definitions = array_column($definition['fields'], null, 'uuid'); $fields = [];
        foreach ($instances->addresses($budget) as $address) {
            $field = $definitions[$address->field] ?? throw new \InvalidArgumentException('Missing field definition.');
            $field['uuid'] = $address->key(); $fields[] = $field;
        }
        $rows = $this->projectFields($fields, $values, $budget);
        foreach ($rows as &$row) {
            $address = \Nicode\FormStudio\Domain\FieldAddress::fromKey($row['field_uuid']);
            $row['field_address'] = $address->key(); $row['field_uuid'] = $address->field;
            $path = [];
            foreach ($address->instances as $instance) { array_push($path, $instance['group'], $instance['instance']); }
            $row['instance_path'] = implode('/', $path);
            $row['instance_hash'] = hash('sha256', $row['instance_path']);
        }
        unset($row);
        return $rows;
    }

    private function projectFields(array $fields, array $values, int $budget = PHP_INT_MAX): array
    {
        $rows = [];
        foreach ($fields as $field) {
            if (!($field['index'] ?? false) || !($field['persist'] ?? true) || !array_key_exists($field['uuid'], $values)) { continue; }
            if (($field['sensitive'] ?? false) && !($field['allow_sensitive_index'] ?? false)) { continue; }
            $provider = $this->fields->get($field['type']); $type = $provider->indexType();
            if ($type === null) { throw new \DomainException('Non-indexable field in projection.'); }
            $items = $provider->multiple() ? $values[$field['uuid']] : [$values[$field['uuid']]];
            foreach ($items as $ordinal => $value) {
                if ($value === null || $value === '') { continue; }
                if ($type === 'keyword' && mb_strlen((string) $value) > 255) { throw new \DomainException('Keyword projection exceeds supported length.'); }
                if (in_array($type, ['date', 'datetime'], true) && \Nicode\FormStudio\Validation\IndexLimits::validate($type, $value, false) !== []) { throw new \DomainException('Temporal projection exceeds portable date range.'); }
                if ($type === 'decimal') {
                    $number = Decimal::normalize($value);
                    if (Decimal::scale($number) > 12 || strlen(explode('.', ltrim($number, '-'))[0]) > 26) { throw new \DomainException('Decimal projection exceeds supported precision.'); }
                }
                if ($type === 'datetime') { $value = str_replace('T', ' ', $value); }
                if ($type === 'boolean') { $value = (int) $value; }
                if (count($rows) >= $budget) { throw new \InvalidArgumentException('Index projection budget exceeded.'); }
                $rows[] = ['field_uuid' => $field['uuid'], 'value_type' => $type, 'ordinal' => $ordinal, 'value_' . $type => $value];
            }
        }
        return $rows;
    }
}
