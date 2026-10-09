<?php
declare(strict_types=1);
$temporalForm = $forms->create('Temporal SQL acceptance', 'temporal-sql-' . bin2hex(random_bytes(5)), 1);
$temporalDraft = $forms->draft($temporalForm); $temporalValues = []; $temporalFieldIds = [];
foreach (['date' => '1000-01-01', 'time' => '12:00', 'datetime-local' => '9999-12-31T23:59:59', 'month' => '2026-09', 'week' => '2020-W53'] as $type => $value) {
    $uuid = Nicode\FormStudio\Domain\Uuid::create(); $temporalFieldIds[$type] = $uuid; $temporalValues[$uuid] = $value;
    $temporalDraft['elements'][] = ['uuid' => $uuid, 'type' => 'field', 'parent_uuid' => null];
    $temporalDraft['fields'][] = ['uuid' => $uuid, 'name' => str_replace('-', '_', $type), 'type' => $type, 'index' => true, 'config' => []];
}
$temporalRevision = $forms->saveDraft($temporalForm, 0, $temporalDraft, 1); $temporalVersion = $forms->publish($temporalForm, $temporalRevision, 1); $temporalSpec = $forms->version($temporalForm, $temporalVersion);
$temporalAttempt = hash('sha256', random_bytes(32)); $ancientValues = array_replace($temporalValues, [$temporalFieldIds['date'] => '0001-01-01']);
try { $submissions->persist($temporalForm, $temporalVersion, $temporalSpec, $ancientValues, $temporalAttempt); throw new LogicException('Unsupported temporal projection persisted.'); } catch (DomainException) {}
if ($connection->row('SELECT id FROM ' . $connection->table('submissions') . ' WHERE form_id = :id', [':id' => $temporalForm]) !== null) { throw new RuntimeException('Failed temporal projection escaped rollback.'); }
$temporalResponse = $submissions->persist($temporalForm, $temporalVersion, $temporalSpec, $temporalValues, $temporalAttempt);
$temporalPayload = json_decode($submissions->get($temporalForm, $temporalResponse->id)['canonical_payload'], true, 64, JSON_THROW_ON_ERROR)['values'];
foreach ($temporalValues as $uuid => $value) { if ($temporalPayload[$uuid] !== $value) { throw new RuntimeException('Local temporal value changed in canonical storage.'); } }
$temporalIndexes = $connection->rows('SELECT field_uuid, value_type, value_date, value_datetime, value_keyword FROM ' . $connection->table('submission_index') . ' WHERE submission_id = :id', [':id' => $temporalResponse->id]);
if (count($temporalIndexes) !== 5) { throw new RuntimeException('Temporal index rows missing.'); }
foreach ($temporalIndexes as $row) {
    $value = $row['value_' . $row['value_type']];
    if ($row['value_type'] === 'datetime') { $value = str_replace(' ', 'T', substr($value, 0, 19)); }
    if ($value !== $temporalValues[$row['field_uuid']]) { throw new RuntimeException('Temporal projection changed value across database engines.'); }
}
echo "Temporal SQL: portable date boundaries, all five projections, exact local canonical values and invalid-index rollback passed.\n";
