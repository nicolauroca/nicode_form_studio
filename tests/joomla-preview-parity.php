<?php
declare(strict_types=1);
$_SERVER['HTTP_HOST'] = '127.0.0.1:13371'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
require __DIR__ . '/joomla-config.php';
$runtime = new Joomla\DI\Container($container);
$runtime->registerServiceProvider(new Nicode\FormStudio\Infrastructure\Joomla\RuntimeProvider($app, new Joomla\Registry\Registry(['captcha_mode' => 'none']), $site));
$administration = $runtime->get(Nicode\FormStudio\Application\FormAdministration::class);
$form = $administration->create('Preview prefill parity', 'preview-parity-' . bin2hex(random_bytes(6)), (int) $admin->id);
$draft = $administration->edit($form, (int) $admin->id)['draft']; $ids = [];
foreach (['text' => ['max_length' => 5], 'integer' => ['min' => 1, 'max' => 10], 'email' => []] as $type => $constraints) {
    $uuid = Nicode\FormStudio\Domain\Uuid::create(); $ids[$type] = $uuid;
    $draft['elements'][] = ['uuid' => $uuid, 'type' => 'field', 'parent_uuid' => null];
    $draft['fields'][] = ['uuid' => $uuid, 'type' => $type, 'name' => $type, 'config' => ['label' => $type, 'required' => true] + $constraints, 'prefill' => ['type' => 'query', 'key' => $type]];
}
$revision = $administration->save($form, 0, $draft, (int) $admin->id);
$version = $administration->publish($form, $revision, (int) $admin->id);
$project = static function (string $html) use (&$ids): array {
    $document = new DOMDocument(); $previous = libxml_use_internal_errors(true);
    try { $document->loadHTML($html); } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    $xpath = new DOMXPath($document); $result = [];
    foreach ($ids as $type => $uuid) {
        $input = $xpath->query('//input[@data-nfs-input="' . $uuid . '"]')->item(0);
        if (!$input) { throw new RuntimeException('Missing parity field.'); }
        foreach (['type', 'value', 'min', 'max', 'maxlength'] as $attribute) { $result[$type][$attribute] = $input->getAttribute($attribute); }
        foreach (['required', 'readonly', 'disabled'] as $attribute) { $result[$type][$attribute] = $input->hasAttribute($attribute); }
        $result[$type]['inactive'] = $xpath->query('ancestor::*[@hidden]', $input)->length > 0;
        $result[$type]['label'] = trim($xpath->query('//label[@for="' . $input->getAttribute('id') . '"]')->item(0)?->textContent ?? '');
    }
    return $result;
};
foreach ([['text' => 'too long', 'integer' => 'not numeric', 'email' => 'invalid'], ['text' => 'valid', 'integer' => '7', 'email' => 'test@example.test'], ['text' => 'valid', 'integer' => '11', 'email' => 'test@example.test']] as $query) {
    $trusted = ['language' => 'en-GB', 'view_levels' => [1], 'prefill_query' => $query];
    $preview = $runtime->get(Nicode\FormStudio\Application\FormPreview::class)->render($form, (int) $admin->id, trustedContext: $trusted);
    if (!is_string($preview['html']) || !str_contains($preview['html'], 'data-nfs-submit disabled')) { throw new RuntimeException('Prefill preview failed or allowed submission.'); }
    $projected = $project($preview['html']);
    foreach (['component', 'module'] as $channel) {
        $context = new Nicode\FormStudio\Submission\RequestContext((int) $admin->id, [1], 'en-GB', 'preview-parity-session', str_repeat('a', 64), true, $channel, prefillQuery: $query);
        $public = $runtime->get(Nicode\FormStudio\Application\FormDisplay::class)->render($form, $context, 'parity-' . $channel, '/index.php', 'csrf');
        if ($project($public['html']) !== $projected) { throw new RuntimeException('Preview/public prefill value or input constraint mismatch.'); }
    }
    if ($query['text'] === 'too long' && array_filter(array_column($projected, 'value')) !== []) { throw new RuntimeException('Invalid prefills were not cleared.'); }
    if ($query['integer'] === '11' && $projected['integer']['value'] !== '') { throw new RuntimeException('Out-of-range prefill remained visible.'); }
    if ($query['integer'] === '7' && array_column($projected, 'value') !== ['valid', '7', 'test@example.test']) { throw new RuntimeException('Valid prefills were not retained.'); }
}
$group = Nicode\FormStudio\Domain\Uuid::create();
$ids['copy'] = Nicode\FormStudio\Domain\Uuid::create();
$draft['elements'][0]['parent_uuid'] = $group;
$draft['elements'][] = ['uuid' => $group, 'type' => 'fieldset', 'parent_uuid' => null, 'title' => 'Source group'];
$draft['elements'][] = ['uuid' => $ids['copy'], 'type' => 'field', 'parent_uuid' => null];
$draft['fields'][] = ['uuid' => $ids['copy'], 'type' => 'text', 'name' => 'copy', 'config' => ['label' => 'Copied answer', 'readonly' => true], 'prefill' => ['type' => 'field', 'field' => $ids['text']]];
$draft['fields'][2]['config']['required'] = false;
$draft['base_language'] = 'en-GB';
$draft['translations'] = ['es-ES' => ['fields' => [$ids['copy'] => ['label' => 'Respuesta copiada']]]];
foreach ([8 => [['type' => 'hide', 'target' => $group]], 9 => [['type' => 'required', 'target' => $ids['email']]], 10 => [['type' => 'set_value', 'target' => $ids['copy'], 'value' => 'rule']]] as $number => $effects) {
    $draft['rules'][] = ['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'when' => ['field' => $ids['integer'], 'operator' => 'equals', 'value' => $number], 'effects' => $effects];
}
$revision = $administration->save($form, (int) $administration->edit($form, (int) $admin->id)['form']['draft_revision'], $draft, (int) $admin->id);
$version = $administration->publish($form, $revision, (int) $admin->id);
// A historical preview must remain equivalent to the published snapshot even
// after the draft changes its derived-field presentation and rule effects.
$draft['translations']['es-ES']['fields'][$ids['copy']]['label'] = 'Draft only';
$draft['rules'] = [];
$administration->save($form, (int) $administration->edit($form, (int) $admin->id)['form']['draft_revision'], $draft, (int) $admin->id);
$currentPreview = $runtime->get(Nicode\FormStudio\Application\FormPreview::class)->render($form, (int) $admin->id, trustedContext: ['language' => 'es-ES']);
if ($project($currentPreview['html'])['copy']['label'] !== 'Draft only') { throw new RuntimeException('Draft fixture did not diverge from the published translation.'); }
foreach (['en-GB', 'es-ES', 'fr-FR'] as $locale) {
    foreach ([7, 8, 9, 10] as $number) {
        $query = ['text' => 'valid', 'integer' => (string) $number, 'email' => 'test@example.test'];
        $preview = $runtime->get(Nicode\FormStudio\Application\FormPreview::class)->render($form, (int) $admin->id, trustedContext: ['language' => $locale, 'view_levels' => [1], 'prefill_query' => $query], version: $version);
        $expected = $project($preview['html']);
        foreach (['component', 'module'] as $channel) {
            $context = new Nicode\FormStudio\Submission\RequestContext((int) $admin->id, [1], $locale, 'preview-parity-session', str_repeat('a', 64), true, $channel, prefillQuery: $query);
            $public = $runtime->get(Nicode\FormStudio\Application\FormDisplay::class)->render($form, $context, 'rules-' . $channel, '/index.php', 'csrf');
            if ($project($public['html']) !== $expected) { throw new RuntimeException('Historical preview differs from published rules or translations.'); }
        }
        if ($expected['copy']['value'] !== match ($number) { 8 => '', 10 => 'rule', default => 'valid' }
            || $expected['text']['inactive'] !== ($number === 8)
            || $expected['email']['required'] !== ($number === 9)
            || !$expected['copy']['readonly']
            || $expected['copy']['label'] !== ($locale === 'es-ES' ? 'Respuesta copiada' : 'Copied answer')) {
            throw new RuntimeException('Derived prefill, inherited visibility, conditional requirement, rule precedence or translation fallback failed: ' . json_encode([$locale, $number, $expected], JSON_THROW_ON_ERROR));
        }
    }
}
$db = $runtime->get(Nicode\FormStudio\Infrastructure\Database\Connection::class);
if ($db->rows('SELECT id FROM ' . $db->table('submissions') . ' WHERE form_id = :form', [':form' => $form]) !== []) { throw new RuntimeException('Read-only preview created a submission.'); }
file_put_contents(__DIR__ . '/../build/joomla-preview-parity-results.json', json_encode(['passed' => true, 'timestamp' => gmdate(DATE_ATOM), 'comparisons' => 30, 'locales' => ['en-GB', 'es-ES', 'fr-FR'], 'checks' => ['invalid and valid initial prefill', 'boolean attribute presence', 'derived field copy', 'inactive ancestor and source', 'conditional required', 'rule precedence', 'translated label and fallback', 'historical snapshot after draft mutation', 'disabled preview submit and no responses']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Native preview parity: invalid/valid/bounded prefill and input constraints match component/module rendering; preview submission stays disabled.\n";
echo "Native historical preview parity: derived values, inactive source, conditional required, rule precedence and three locales match component/module snapshots after draft changes.\n";
