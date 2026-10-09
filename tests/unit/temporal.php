<?php
declare(strict_types=1);
test('shared temporal validation covers real calendars and exact inclusive bounds', function (): void {
    $registry = new Nicode\FormStudio\Registry\FieldTypeRegistry(); Nicode\FormStudio\Field\CoreFieldTypes::register($registry);
    foreach (json_decode(file_get_contents(__DIR__ . '/../fixtures/temporal.json'), true, 32, JSON_THROW_ON_ERROR) as $case) {
        $field = $registry->get($case['type'] === 'datetime' ? 'datetime-local' : $case['type']);
        same($case['errors'], $field->validate($case['value'], $case['config']));
    }
});
test('temporal field metadata and compiler configuration reject malformed and reversed bounds', function (): void {
    $registry = new Nicode\FormStudio\Registry\FieldTypeRegistry(); Nicode\FormStudio\Field\CoreFieldTypes::register($registry);
    foreach (['date' => ['2026-09-28', '2026-09-27', '2026-02-30'], 'time' => ['12:01', '12:00:00', '24:00'], 'datetime-local' => ['2026-09-28T12:00', '2026-09-27T12:00', '2026-02-30T12:00'], 'month' => ['2026-10', '2026-09', '2026-13'], 'week' => ['2026-W40', '2026-W39', '2021-W53']] as $id => [$later, $earlier, $invalid]) {
        $field = $registry->get($id);
        same(true, isset($field->metadata()['configuration_schema']['properties']['min']));
        same([], $field->validateConfiguration(['min' => $earlier, 'max' => $later], '/field'));
        same('field.configuration.range', $field->validateConfiguration(['min' => $later, 'max' => $earlier], '/field')[0]->code);
        same('field.configuration.temporal', $field->validateConfiguration(['min' => $invalid], '/field')[0]->code);
    }
});
test('publication rejects invalid temporal condition operands and accepts equivalent minute and second bounds', function (): void {
    $draft = definition(); $draft['fields'][0]['type'] = 'time'; $uuid = $draft['fields'][0]['uuid'];
    $draft['fields'][0]['config'] = ['min' => '12:00:00', 'max' => '12:00', 'default' => '12:00'];
    same(true, compiler()->compile($draft)->successful());
    $draft['rules'] = [['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'enabled' => true, 'priority' => 1, 'when' => ['field' => $uuid, 'operator' => 'after', 'value' => '24:00'], 'effects' => [['type' => 'required', 'target' => $uuid]]]];
    same(true, in_array('operator.value.temporal', codes($draft), true));
    $draft['rules'][0]['when']['value'] = '12:00:00';
    same(true, compiler()->compile($draft)->successful());
});
test('indexed temporal values respect the portable SQL range without restricting unindexed ancient dates', function (): void {
    same(['index_date'], Nicode\FormStudio\Validation\IndexLimits::validate('date', '0001-01-01', false));
    same(['index_date'], Nicode\FormStudio\Validation\IndexLimits::validate('datetime', '0999-12-31T23:59:59', false));
    same([], Nicode\FormStudio\Validation\IndexLimits::validate('date', '1000-01-01', false));
    same([], Nicode\FormStudio\Validation\IndexLimits::validate('datetime', '9999-12-31T23:59:59', false));
});
