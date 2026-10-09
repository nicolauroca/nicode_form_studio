<?php
declare(strict_types=1);

test('step descriptions are typed localized escaped and linked per rendered instance', function (): void {
    $draft = definition(); $step = Nicode\FormStudio\Domain\Uuid::create();
    $draft['elements'][0]['parent_uuid'] = $step;
    $draft['elements'][] = ['uuid' => $step, 'type' => 'step', 'title' => 'Contact', 'description' => 'Base description'];
    foreach ([null, false, 1, [], ['text' => 'wrong']] as $invalid) {
        $candidate = $draft; $candidate['elements'][1]['description'] = $invalid;
        $result = compiler()->compile($candidate); same(false, $result->successful());
        same('/elements/1/description', $result->diagnostics[0]->path);
    }
    $draft['translations'] = ['es-ES' => ['elements' => [$step => ['title' => 'Contacto', 'description' => '<img src=x onerror=alert(1)> Instructions']]]];
    $original = compiler()->compile($draft)->spec; same(true, $original !== null);
    $spec = Nicode\FormStudio\Translation\DefinitionTranslations::spec($original, 'es-ES');
    same('Base description', $original->toArray()['elements'][1]['description']);
    $renderers = new Nicode\FormStudio\Rendering\FieldRendererRegistry(); $renderers->register('text', new Nicode\FormStudio\Rendering\CoreFieldRenderer());
    $renderer = new Nicode\FormStudio\Rendering\FormRenderer($renderers, new Nicode\FormStudio\Rendering\PublicSpec(registry()));
    foreach (['step-page', 'step-module'] as $instance) {
        $html = $renderer->render($spec, new Nicode\FormStudio\Rendering\RenderContext($instance, 1, 2, 'index.php?task=form.submit', 'csrf', 'attempt'), rules()->evaluate($spec, []));
        $document = new DOMDocument(); @$document->loadHTML($html); $xpath = new DOMXPath($document);
        $node = $xpath->query('//*[@data-nfs-step="' . $step . '"]')->item(0);
        same('Contacto', $node->getAttribute('aria-label'));
        same($instance . '-' . $step . '-description', $node->getAttribute('aria-describedby'));
        $description = $xpath->query('//*[@id="' . $node->getAttribute('aria-describedby') . '"]')->item(0);
        same('<img src=x onerror=alert(1)> Instructions', $description->textContent);
        same(0, $xpath->query('.//img|.//script', $node)->length);
    }
    $draft['elements'][1]['description'] = ''; unset($draft['translations']);
    $empty = compiler()->compile($draft)->spec;
    $html = $renderer->render($empty, new Nicode\FormStudio\Rendering\RenderContext('empty-step', 1, 2, 'index.php?task=form.submit', 'csrf', 'attempt'), rules()->evaluate($empty, []));
    same(false, str_contains($html, '-description"'));
});
