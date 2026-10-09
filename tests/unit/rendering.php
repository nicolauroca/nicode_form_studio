<?php
declare(strict_types=1);

use Nicode\FormStudio\Rendering\CoreFieldRenderer;
use Nicode\FormStudio\Rendering\FieldRendererRegistry;
use Nicode\FormStudio\Rendering\FormRenderer;
use Nicode\FormStudio\Rendering\PublicSpec;
use Nicode\FormStudio\Rendering\RenderContext;

test('traditional error summary links resolve to focusable field containers including choice groups', function (): void {
    foreach (['radio','checkbox-group','button-group','text'] as $type) {
        $draft = definition(); $uuid = $draft['fields'][0]['uuid'];
        $draft['fields'][0]['type'] = $type;
        $draft['fields'][0]['options'] = [['uuid'=>Nicode\FormStudio\Domain\Uuid::create(),'value'=>'one','label'=>'One','enabled'=>true]];
        $compiled = compiler()->compile($draft); same(true, $compiled->successful());
        $renderers = new FieldRendererRegistry(); $renderers->register($type, new CoreFieldRenderer());
        $renderer = new FormRenderer($renderers, new PublicSpec(registry()));
        foreach (['page','module'] as $instance) {
            $html = $renderer->render($compiled->spec, new RenderContext($instance,1,2,'index.php?task=form.submit','csrf','attempt'), rules()->evaluate($compiled->spec,[]), [$uuid=>['Required']]);
            $document = new DOMDocument(); @$document->loadHTML($html); $xpath = new DOMXPath($document);
            $link = $xpath->query('//div[contains(@class,"nfs-validation-summary")]//a')->item(0);
            $target = $xpath->query('//*[@id="'.substr($link->getAttribute('href'),1).'"]');
            same(1, $target->length); same($uuid, $target->item(0)->getAttribute('data-nfs-element'));
            same('-1', $target->item(0)->getAttribute('tabindex'));
            same($uuid, $link->getAttribute('data-nfs-error-target'));
        }
    }
});

test('presentation elements use semantic tags and safely escape HTML without a sanitizer', function (): void {
    $draft = definition(); $ids = [];
    foreach (['heading', 'subheading', 'paragraph', 'notice', 'safe-html', 'separator', 'spacer'] as $type) {
        $uuid = Nicode\FormStudio\Domain\Uuid::create(); $ids[$type] = $uuid;
        $draft['elements'][] = ['uuid' => $uuid, 'type' => $type, 'text' => '<img src=x onerror=alert(1)>', 'parent_uuid' => null];
    }
    $spec = compiler()->compile($draft)->spec; same(true, $spec !== null);
    $renderers = new FieldRendererRegistry(); $renderers->register('text', new CoreFieldRenderer());
    $html = (new FormRenderer($renderers, new PublicSpec(registry())))->render($spec, new RenderContext('presentation', 1, 2, 'index.php?task=form.submit', 'csrf', 'attempt'), rules()->evaluate($spec, []));
    $document = new DOMDocument(); @$document->loadHTML($html); $xpath = new DOMXPath($document);
    foreach ($ids as $type => $uuid) {
        $node = $xpath->query('//*[@data-nfs-element="' . $uuid . '"]')->item(0);
        same(match ($type) { 'heading' => 'h2', 'subheading' => 'h3', 'paragraph' => 'p', default => 'div' }, $node->tagName);
        same(0, $xpath->query('.//img|.//script|.//input', $node)->length);
        if ($type === 'separator') { same(1, $xpath->query('./hr', $node)->length); }
        else { same('<img src=x onerror=alert(1)>', $node->textContent); }
    }
});

test('renderer escapes values and isolates DOM identity across instances', function (): void {
    $draft = definition(); $uuid = $draft['fields'][0]['uuid']; $draft['fields'][0]['config']['label'] = '<img src=x onerror=alert(1)>';
    $spec = compiler()->compile($draft)->spec; $renderers = new FieldRendererRegistry(); $renderers->register('text', new CoreFieldRenderer());
    $renderer = new FormRenderer($renderers, new PublicSpec(registry())); $state = rules()->evaluate($spec, [$uuid => '"><script>alert(1)</script>']);
    $first = $renderer->render($spec, new RenderContext('instance-a', 1, 2, 'index.php?task=form.submit', 'csrfToken', 'attempt', ['form_unavailable' => 'No disponible "<seguro>"']), $state);
    $second = $renderer->render($spec, new RenderContext('instance-b', 1, 2, 'index.php?task=form.submit', 'csrfToken', 'attempt'), $state);
    same(false, str_contains($first, '<img')); same(false, str_contains($first, '<script>alert'));
    same(true, str_contains($first, 'for="instance-a-' . $uuid . '"')); same(true, str_contains($second, 'for="instance-b-' . $uuid . '"'));
    same(true, str_contains($first, 'aria-describedby="instance-a-' . $uuid . '-help"'));
    same(true, str_contains($first, 'data-nfs-unavailable="No disponible &quot;&lt;seguro&gt;&quot;"'));
    raises(InvalidArgumentException::class, fn () => new RenderContext('x', 1, 2, '//evil.example', 'csrf', 'attempt'));
});
test('browser projection never includes action credentials or admin labels', function (): void {
    $draft = definition(); $draft['actions'] = [['type' => 'webhook', 'config' => ['secret' => 'do-not-leak']]];
    $draft['fields'][0]['config']['admin_label'] = 'private administration';
    $public = (new PublicSpec(registry()))->project(new Nicode\FormStudio\Domain\FormSpec($draft));
    same(false, isset($public['actions'])); same(false, str_contains(json_encode($public), 'do-not-leak')); same(false, str_contains(json_encode($public), 'private administration'));
});

test('file picker accept hint uses the same extension policy as the server', function (): void {
    $field = ['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'type' => 'file', 'name' => 'document', 'config' => ['extensions' => ['pdf', 'txt']]];
    $html = (new CoreFieldRenderer())->render($field, 'file-instance', null);
    same(true, str_contains($html, 'accept=".pdf,.txt"'));
});

test('native controls do not impose an undeclared integer or minute-only step', function (): void {
    foreach (['decimal' => 'any', 'currency' => 'any', 'number' => 'any', 'time' => '1', 'datetime-local' => '1'] as $type => $step) {
        $field = ['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'type' => $type, 'name' => 'value', 'config' => []];
        same(true, str_contains((new CoreFieldRenderer())->render($field, 'step-instance', null), 'step="' . $step . '"'));
        $field['config']['step'] = '5';
        same(true, str_contains((new CoreFieldRenderer())->render($field, 'step-instance', null), 'step="5"'));
    }
});

test('browser projection strips private option and rule metadata at every nesting level', function (): void {
    $draft = definition(); $uuid = $draft['fields'][0]['uuid'];
    $option = ['value' => 'public', 'label' => 'Public label', 'enabled' => true, 'secret' => 'private-marker'];
    $draft['fields'][0]['options'] = [$option];
    $draft['fields'][0]['validators'] = [['type' => 'custom_provider', 'config' => ['api_key' => 'private-marker']]];
    $draft['validators'] = [['type' => 'confirmation', 'config' => ['fields' => [$uuid], 'private_note' => 'private-marker']]];
    $draft['fields'][0]['source'] = ['type' => 'option_set', 'dependencies' => [$uuid], 'config' => ['options' => [$option + ['when' => [$uuid => 'yes']]], 'secret' => 'private-marker']];
    $draft['rules'] = [['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'admin_label' => 'private-marker', 'when' => ['group' => 'AND', 'secret' => 'private-marker', 'children' => [['field' => $uuid, 'operator' => 'equals', 'value' => 'yes', 'secret' => 'private-marker']]], 'effects' => [['target' => $uuid, 'type' => 'change_options', 'value' => [$option], 'secret' => 'private-marker']]]];
    $public = (new PublicSpec(registry()))->project(new Nicode\FormStudio\Domain\FormSpec($draft));
    same(false, str_contains(json_encode($public), 'private-marker'));
    same(true, $public['fields'][0]['validators'][0]['server_only']);
    same([$uuid => 'yes'], $public['fields'][0]['source']['config']['options'][0]['when']);
    same('Public label', $public['rules'][0]['effects'][0]['value'][0]['label']);
});

test('public option-default recomputation is restricted to server-derived readonly selections', function (): void {
    foreach (['select' => 'single', 'multiselect' => 'multiple'] as $type => $mode) {
        $draft = definition(); $uuid = $draft['fields'][0]['uuid'];
        $draft['fields'][0]['type'] = $type; $draft['fields'][0]['config'] = ['readonly' => true];
        $draft['fields'][0]['options'] = [['value' => 'a', 'label' => 'A', 'default' => true]];
        $spec = compiler()->compile($draft)->spec;
        $project = new PublicSpec(registry());
        $state = rules()->evaluate($spec, [], [], [$uuid => true]);
        same($mode, $project->project($spec, $state)['fields'][0]['option_defaults']);
        same(true, $project->project($spec, $state)['fields'][0]['options'][0]['default']);
        same(false, isset($project->project($spec, rules()->evaluate($spec, [$uuid => 'a']))['fields'][0]['option_defaults']));
        $draft['fields'][0]['config']['readonly'] = false;
        same(false, isset($project->project(compiler()->compile($draft)->spec)['fields'][0]['option_defaults']));
        $draft['fields'][0]['config']['readonly'] = true;
        $draft['fields'][0]['config']['default'] = $type === 'select' ? 'a' : ['a'];
        same(false, isset($project->project(compiler()->compile($draft)->spec)['fields'][0]['option_defaults']));
    }
});
