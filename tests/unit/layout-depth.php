<?php
declare(strict_types=1);

test('repeatable groups cannot silently compile as nonrepeating containers', function (): void {
    $draft = definition(); $uuid = Nicode\FormStudio\Domain\Uuid::create();
    $draft['elements'][] = ['uuid'=>$uuid,'type'=>'repeatable-group'];
    $draft['elements'][0]['parent_uuid'] = $uuid;
    $result = compiler()->compile($draft); same(false, $result->successful());
    $errors = array_values(array_filter($result->diagnostics, fn ($error) => $error->code === 'layout.repeatable.limits'));
    same(1, count($errors)); same('/elements/1/repeat', $errors[0]->path);
    same(null, $result->spec);
});

test('compiler depth boundary matches renderer including empty deepest containers', function (): void {
    $build = static function (int $levels, bool $field): array {
        $draft = definition(); $draft['elements'] = []; $parent = null;
        for ($i = 0; $i < $levels; $i++) {
            $uuid = Nicode\FormStudio\Domain\Uuid::create();
            $draft['elements'][] = ['uuid'=>$uuid, 'type'=>'group', 'parent_uuid'=>$parent]; $parent = $uuid;
        }
        if ($field) $draft['elements'][] = ['uuid'=>$draft['fields'][0]['uuid'], 'type'=>'field', 'parent_uuid'=>$parent];
        else $draft['fields'] = [];
        return $draft;
    };
    $renderers = new Nicode\FormStudio\Rendering\FieldRendererRegistry();
    $renderers->register('text', new Nicode\FormStudio\Rendering\CoreFieldRenderer());
    $renderer = new Nicode\FormStudio\Rendering\FormRenderer($renderers, new Nicode\FormStudio\Rendering\PublicSpec(registry()));
    foreach ([false, true] as $field) {
        foreach ([0, 1, 63, 64] as $levels) {
            $draft = $build($levels, $field); $compiled = compiler()->compile($draft);
            same(true, $compiled->successful());
            $html = $renderer->render($compiled->spec, new Nicode\FormStudio\Rendering\RenderContext('depth', 1, 2, 'index.php?task=form.submit', 'csrf', 'attempt'), rules()->evaluate($compiled->spec, []));
            same($levels, substr_count($html, 'class="nfs-element nfs-group"'));
        }
        foreach ([65, 100] as $levels) {
            $draft = $build($levels, $field); $compiled = compiler()->compile($draft);
            same(false, $compiled->successful());
            $errors = array_values(array_filter($compiled->diagnostics, fn ($error) => $error->code === 'layout.depth'));
            same('/elements/64/parent_uuid', $errors[0]->path);
            // Position in the flat list must not affect the actual graph depth.
            $draft['elements'] = array_reverse($draft['elements']); $reversed = compiler()->compile($draft);
            same(false, $reversed->successful());
            same(true, in_array('layout.depth', array_column($reversed->diagnostics, 'code'), true));
        }
    }
});
