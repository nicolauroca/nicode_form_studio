<?php
declare(strict_types=1);

test('version comparison identifies property changes by UUID and keeps ordering explicit', function (): void {
    $a = Nicode\FormStudio\Domain\Uuid::create(); $b = Nicode\FormStudio\Domain\Uuid::create();
    $left = ['fields' => [['uuid' => $a, 'config' => ['label' => 'First']], ['uuid' => $b, 'config' => ['label' => 'Second']]]];
    $right = ['fields' => [$left['fields'][1], ['uuid' => $a, 'config' => ['label' => 'Changed']]]];
    $result = (new Nicode\FormStudio\Domain\SpecDiff())->compare($left, $right);
    same(false, $result['truncated']); same(2, count($result['changes']));
    same('reordered', $result['changes'][0]['kind']); same('/fields/' . $a . '/config/label', $result['changes'][1]['path']);
    same('First', $result['changes'][1]['before']); same('Changed', $result['changes'][1]['after']);
});

test('version comparison distinguishes additions and removals from shifted array positions', function (): void {
    $a = Nicode\FormStudio\Domain\Uuid::create(); $b = Nicode\FormStudio\Domain\Uuid::create();
    $left = ['rules' => [['uuid' => $a, 'priority' => 1]]]; $right = ['rules' => [['uuid' => $b, 'priority' => 1]]];
    $result = (new Nicode\FormStudio\Domain\SpecDiff())->compare($left, $right);
    same(['removed', 'added'], array_column($result['changes'], 'kind'));
    same('/rules/' . $a, $result['changes'][0]['path']); same('/rules/' . $b, $result['changes'][1]['path']);
});

test('version comparison reports truncation, null additions and JSON pointer escaping', function (): void {
    $diff = new Nicode\FormStudio\Domain\SpecDiff();
    same(['changes' => [], 'truncated' => false], $diff->compare(['a' => null], ['a' => null]));
    $result = $diff->compare([], ['a/b~c' => null, 'other' => true], 1);
    same(true, $result['truncated']); same('added', $result['changes'][0]['kind']); same('/a~1b~0c', $result['changes'][0]['path']);
});
