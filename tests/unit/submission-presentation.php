<?php
declare(strict_types=1);
test('Historical answers preserve layout order and mask fields without leaking authoring configuration', function (): void {
    $definition = ['fields' => [
        ['uuid' => 'a', 'name' => 'old', 'type' => 'text', 'config' => ['label' => 'Historical label', 'private' => 'never projected']],
        ['uuid' => 'b', 'name' => 'secret', 'type' => 'text'], ['uuid' => 'p', 'name' => 'password', 'type' => 'password'],
    ], 'elements' => [['uuid' => 'group', 'type' => 'fieldset', 'title' => 'Historical group'], ['uuid' => 'b', 'type' => 'field', 'parent_uuid' => 'group'], ['uuid' => 'a', 'type' => 'field', 'parent_uuid' => 'group'], ['uuid' => 'p', 'type' => 'field']]];
    $layout = Nicode\FormStudio\Application\SubmissionPresentation::layout($definition, ['a' => 'visible', 'p' => 'must not appear'], ['b']);
    same('Historical group', $layout[0]['label']); same(['b', 'a'], array_column($layout[0]['children'], 'uuid'));
    same(true, $layout[0]['children'][0]['masked']); same('Historical label', $layout[0]['children'][1]['label']);
    same(false, str_contains(json_encode($layout), 'private')); same(false, str_contains(json_encode($layout), 'must not appear'));
    same([], Nicode\FormStudio\Application\SubmissionPresentation::layout($definition, [], []));
});
