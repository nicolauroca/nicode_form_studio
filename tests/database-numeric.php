<?php
declare(strict_types=1);
$numericForm = $forms->create('Numeric SQL acceptance', 'numeric-sql-' . bin2hex(random_bytes(5)), 1);
$numericDraft = $forms->draft($numericForm); $numericValues = []; $numericFieldIds = [];
foreach (['integer' => PHP_INT_MAX, 'decimal' => '99999999999999999999999999.999999999999', 'number' => '-99999999999999999999999999.999999999999', 'currency' => '12.5', 'range' => '50'] as $type => $value) {
    $uuid = Nicode\FormStudio\Domain\Uuid::create(); $numericFieldIds[$type] = $uuid; $numericValues[$uuid] = $value;
    $numericDraft['elements'][] = ['uuid' => $uuid, 'type' => 'field', 'parent_uuid' => null];
    $numericDraft['fields'][] = ['uuid' => $uuid, 'name' => $type, 'type' => $type, 'index' => true, 'config' => []];
}
$numericRevision = $forms->saveDraft($numericForm, 0, $numericDraft, 1); $numericVersion = $forms->publish($numericForm, $numericRevision, 1); $numericSpec = $forms->version($numericForm, $numericVersion);
$numericResponse = $submissions->persist($numericForm, $numericVersion, $numericSpec, $numericValues, hash('sha256', random_bytes(32)));
$numericPayload = json_decode($submissions->get($numericForm, $numericResponse->id)['canonical_payload'], true, 64, JSON_THROW_ON_ERROR)['values'];
foreach ($numericValues as $uuid => $value) { if ($numericPayload[$uuid] !== $value) { throw new RuntimeException('Numeric canonical storage lost its logical type or exact value.'); } }
$numericIndexes = $connection->rows('SELECT field_uuid, value_type, value_integer, value_decimal FROM ' . $connection->table('submission_index') . ' WHERE submission_id = :id', [':id' => $numericResponse->id]);
if (count($numericIndexes) !== 5) { throw new RuntimeException('Numeric index rows missing.'); }
foreach ($numericIndexes as $row) {
    $value = Nicode\FormStudio\Validation\Decimal::normalize($row['value_' . $row['value_type']]);
    if ($value !== (string) $numericValues[$row['field_uuid']]) { throw new RuntimeException('Numeric projection rounded a value across database engines.'); }
}
echo "Numeric SQL: five indexed types, signed 64-bit integer and positive/negative DECIMAL(38,12) boundaries preserve exact canonical values and projections.\n";
