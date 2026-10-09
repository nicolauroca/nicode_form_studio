<?php
declare(strict_types=1);

test('schema type diagnostics preserve exact size precision nullability and signedness', function (): void {
    $matches = Nicode\FormStudio\Health\SchemaTypes::matches(...);
    $base = ['is_nullable' => 'NO'];
    foreach ([['data_type' => 'numeric', 'numeric_precision' => '38', 'numeric_scale' => '12'], ['data_type' => 'decimal', 'numeric_precision' => 38, 'numeric_scale' => 12]] as $decimal) {
        same(true, $matches($base + $decimal, 'DECIMAL(38,12)', false));
        same(false, $matches($base + array_replace($decimal, ['numeric_precision' => 37]), 'DECIMAL(38,12)', false));
        same(false, $matches($base + array_replace($decimal, ['numeric_scale' => 11]), 'DECIMAL(38,12)', false));
    }
    same(true, $matches($base + ['data_type' => 'bigint', 'column_type' => 'bigint(20)'], 'BIGINT', false));
    same(false, $matches($base + ['data_type' => 'bigint', 'column_type' => 'bigint unsigned'], 'BIGINT', false));
    same(false, $matches(['is_nullable' => 'YES', 'data_type' => 'bigint'], 'BIGINT', false));
    same(true, $matches($base + ['data_type' => 'character varying', 'character_maximum_length' => 255], 'VARCHAR(255)', false));
    same(false, $matches($base + ['data_type' => 'varchar', 'character_maximum_length' => 128], 'VARCHAR(255)', false));
    same(false, $matches($base + ['data_type' => 'varchar', 'character_maximum_length' => 1020], 'VARBINARY(1020)', false));
    same(true, $matches($base + ['data_type' => 'timestamp without time zone', 'datetime_precision' => 6], 'TIMESTAMP(6) WITHOUT TIME ZONE', false));
    same(false, $matches($base + ['data_type' => 'timestamp with time zone', 'datetime_precision' => 6], 'TIMESTAMP(6) WITHOUT TIME ZONE', false));
    same(false, $matches($base + ['data_type' => 'datetime', 'datetime_precision' => 0], 'DATETIME(6)', false));
});

test('schema storage diagnostics detect identity and collation drift', function (): void {
    $check = Nicode\FormStudio\Health\SchemaTypes::storageProperties(...);
    $pg = ['is_identity' => 'YES', 'identity_generation' => 'BY DEFAULT', 'is_generated' => 'NEVER', 'collation_name' => null];
    same(true, $check($pg, true, true));
    foreach (['is_identity' => 'NO', 'identity_generation' => 'ALWAYS', 'is_generated' => 'ALWAYS', 'collation_name' => 'custom'] as $key => $value) { same(false, $check(array_replace($pg, [$key => $value]), true, true)); }
    same(true, $check(array_replace($pg, ['is_identity' => 'NO']), false, true));
    $mysqlId = ['data_type' => 'bigint', 'extra' => 'auto_increment'];
    same(true, $check($mysqlId, true, false));
    same(false, $check(array_replace($mysqlId, ['extra' => '']), true, false));
    same(false, $check($mysqlId, false, false));
    $text = ['data_type' => 'varchar', 'character_set_name' => 'utf8mb4', 'collation_name' => 'utf8mb4_bin', 'extra' => ''];
    same(true, $check($text, false, false));
    foreach (['character_set_name' => 'utf8mb3', 'collation_name' => 'utf8mb4_general_ci', 'extra' => 'STORED GENERATED'] as $key => $value) { same(false, $check(array_replace($text, [$key => $value]), false, false)); }
    same(true, $check(['data_type' => 'varbinary', 'extra' => ''], false, false));
});
