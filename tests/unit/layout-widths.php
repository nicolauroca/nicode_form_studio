<?php
declare(strict_types=1);

test('all conceptual widths compile without inheriting other breakpoints and render exact classes', function (): void {
    $renderers = new Nicode\FormStudio\Rendering\FieldRendererRegistry();
    $renderers->register('text', new Nicode\FormStudio\Rendering\CoreFieldRenderer());
    $renderer = new Nicode\FormStudio\Rendering\FormRenderer($renderers, new Nicode\FormStudio\Rendering\PublicSpec(registry()));
    foreach (['mobile', 'tablet', 'desktop'] as $breakpoint) {
        foreach (range(1, 12) as $width) {
            $draft = definition(); $draft['elements'][0]['width'] = [$breakpoint => $width];
            $compiled = compiler()->compile($draft); same(true, $compiled->successful());
            same([$breakpoint => $width], $compiled->spec->toArray()['elements'][0]['width']);
            $html = $renderer->render($compiled->spec, new Nicode\FormStudio\Rendering\RenderContext('width-test', 1, 2, 'index.php?task=form.submit', 'csrf', 'attempt'), rules()->evaluate($compiled->spec, []));
            $document = new DOMDocument(); @$document->loadHTML($html); $xpath = new DOMXPath($document);
            $element = $xpath->query('//*[@data-nfs-element="'.$draft['elements'][0]['uuid'].'"]')->item(0);
            $classes = explode(' ', $element->getAttribute('class'));
            same(true, in_array('nfs-'.$breakpoint.'-'.$width, $classes, true));
            foreach (array_diff(['mobile', 'tablet', 'desktop'], [$breakpoint]) as $absent) {
                same([], array_values(array_filter($classes, fn ($class) => str_starts_with($class, 'nfs-'.$absent.'-'))));
            }
        }
    }
});

test('invalid conceptual widths fail compilation at the owning element', function (): void {
    foreach ([0, 13, -1, 1.5, '6', true, null, [], ['span' => 6]] as $width) {
        $draft = definition(); $draft['elements'][0]['width'] = ['desktop' => $width];
        $result = compiler()->compile($draft); same(false, $result->successful());
        $errors = array_values(array_filter($result->diagnostics, fn ($diagnostic) => $diagnostic->code === 'layout.width'));
        same(1, count($errors)); same('/elements/0/width', $errors[0]->path);
    }
    $draft = definition(); $draft['elements'][0]['width'] = ['col-md' => 6];
    $result = compiler()->compile($draft); same(false, $result->successful());
    same(true, in_array('layout.width', array_column($result->diagnostics, 'code'), true));
});
