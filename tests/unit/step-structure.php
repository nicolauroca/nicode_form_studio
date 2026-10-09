<?php
declare(strict_types=1);

test('steps allow grouping but reject direct and indirect nested pages with original paths', function (): void {
    $draft = definition();
    $outer = Nicode\FormStudio\Domain\Uuid::create(); $inner = Nicode\FormStudio\Domain\Uuid::create(); $group = Nicode\FormStudio\Domain\Uuid::create();
    $draft['elements'][] = ['uuid' => $outer, 'type' => 'step'];
    $draft['elements'][] = ['uuid' => $group, 'type' => 'group', 'parent_uuid' => $outer];
    $draft['elements'][] = ['uuid' => $inner, 'type' => 'step'];
    $draft['elements'][0]['parent_uuid'] = $group;
    same(true, compiler()->compile($draft)->successful());
    foreach ([$outer, $group] as $parent) {
        $draft['elements'][3]['parent_uuid'] = $parent;
        $result = compiler()->compile($draft); same(false, $result->successful());
        $errors = array_values(array_filter($result->diagnostics, fn ($d) => $d->code === 'layout.step.nested'));
        same(1, count($errors)); same('/elements/3/parent_uuid', $errors[0]->path);
    }
    $draft['elements'][2]['parent_uuid'] = null;
    $draft['elements'][1]['parent_uuid'] = $group;
    same(true, compiler()->compile($draft)->successful());
    // Malformed ancestor cycles must terminate and retain cycle diagnostics.
    $draft['elements'][2]['parent_uuid'] = $group;
    same(true, in_array('layout.cycle', array_column(compiler()->compile($draft)->diagnostics, 'code'), true));
});
