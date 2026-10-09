<?php
declare(strict_types=1);
$textForm = $forms->create('Text SQL acceptance', 'text-sql-' . bin2hex(random_bytes(5)), 1);
$textDraft = $forms->draft($textForm); $textValues = []; $textFieldIds = [];
foreach (['text' => '😀 ń ñ', 'textarea' => "line one\nline two 😀", 'email' => 'User+tag@example.test', 'telephone' => '+34 600 123 456', 'url' => 'https://xn--maana-pta.test/%C3%B1', 'search' => '  term  ', 'password' => ' x ', 'hidden' => ' hidden ', 'color' => '#12aBcD'] as $type => $value) {
    $uuid = Nicode\FormStudio\Domain\Uuid::create(); $textFieldIds[$type] = $uuid; $textValues[$uuid] = $value;
    $textDraft['elements'][] = ['uuid' => $uuid, 'type' => 'field', 'parent_uuid' => null];
    $textDraft['fields'][] = ['uuid' => $uuid, 'name' => $type, 'type' => $type, 'index' => $type !== 'password', 'config' => ['trim' => false]];
}
$textRevision = $forms->saveDraft($textForm, 0, $textDraft, 1);
$textVersion = $forms->publish($textForm, $textRevision, 1); $textSpec = $forms->version($textForm, $textVersion);
$textResponse = $submissions->persist($textForm, $textVersion, $textSpec, $textValues, hash('sha256', random_bytes(32)));
$textPayload = json_decode($submissions->get($textForm, $textResponse->id)['canonical_payload'], true, 64, JSON_THROW_ON_ERROR)['values'];
$expectedText = $textValues; unset($expectedText[$textFieldIds['password']]);
foreach ($expectedText as $uuid => $value) { if (($textPayload[$uuid] ?? null) !== $value) { throw new RuntimeException('Text canonical value changed across database engines.'); } }
if (array_key_exists($textFieldIds['password'], $textPayload) || count($textPayload) !== 8) { throw new RuntimeException('Password escaped persistence exclusion.'); }
$textIndexes = $connection->rows('SELECT field_uuid, value_type, value_keyword, value_text FROM ' . $connection->table('submission_index') . ' WHERE submission_id = :id', [':id' => $textResponse->id]);
if (count($textIndexes) !== 8) { throw new RuntimeException('Text projection cardinality mismatch.'); }
foreach ($textIndexes as $row) {
    $type = $row['field_uuid'] === $textFieldIds['textarea'] ? 'text' : 'keyword';
    if ($row['value_type'] !== $type || $row['value_' . $type] !== ($expectedText[$row['field_uuid']] ?? null)) { throw new RuntimeException('Text projection lost whitespace, Unicode, letter case or provider type.'); }
}
echo "Text SQL: eight text providers plus color preserve Unicode, whitespace, line breaks and case; eight exact projections and password exclusion verified.\n";
