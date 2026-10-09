<?php
declare(strict_types=1);

test('compiler checks post-submit behavior preservation messages and conditional references before activation', function (): void {
    $base = definition(); $field = $base['fields'][0]['uuid'];
    $condition = ['field' => $field, 'operator' => 'equals', 'value' => 'yes'];
    $cases = [
        ['behavior' => 'unknown'], ['show_reference' => 'yes'], ['preserve' => 'all'],
        ['preserve' => ['missing-field']], ['messages' => 'plain string'],
        ['summary_fields' => 'all'], ['summary_fields' => ['missing-field']],
        ['messages' => ['success' => ['nested']]], ['messages' => ['success' => '{{field.' . $field . '.value}}']],
        ['conditional_messages' => 'invalid'], ['conditional_messages' => [['condition' => $condition, 'message' => 123]]],
        ['conditional_messages' => [['condition' => ['field' => 'missing', 'operator' => 'equals', 'value' => 'yes'], 'message' => 'Thanks']]],
        ['conditional_messages' => [['condition' => $condition, 'message' => '{{secret}}']]],
        ['conditional_messages' => [['uuid' => 'invalid', 'condition' => $condition, 'message' => 'Thanks']]],
    ];
    foreach ($cases as $post) {
        $result = compiler()->compile($base + ['post_submit' => $post]);
        same(false, $result->successful());
        same(true, count(array_filter($result->diagnostics, static fn ($error): bool => $error->severity === 'ERROR' && str_starts_with($error->path, '/post_submit'))) > 0);
    }
    $uuid = Nicode\FormStudio\Domain\Uuid::create();
    $candidate = ['uuid' => $uuid, 'condition' => $condition, 'message' => 'Thanks {{submission.reference}}'];
    same(false, compiler()->compile($base + ['post_submit' => ['conditional_messages' => [$candidate, $candidate]]])->successful());
    foreach (['keep','hide','reset'] as $behavior) {
        $post = ['behavior' => $behavior, 'show_reference' => false, 'preserve' => [$field], 'messages' => ['success' => 'Received {{submission.reference}} for {{form.name}} on {{submission.date}}'], 'conditional_messages' => [$candidate, ['condition' => $condition, 'message' => 'Legacy candidate without UUID']]];
        $compiled = compiler()->compile($base + ['post_submit' => $post]);
        same(true, $compiled->successful()); same($post, $compiled->spec->toArray()['post_submit']);
    }
});

test('result message categories reject typos and retain localized per-category fallbacks', function (): void {
    $base = definition();
    foreach (['sucess', 'validation_eror', 0] as $category) {
        $draft = $base; $draft['post_submit']['messages'] = [$category => 'Silently ignored before'];
        $compiled = compiler()->compile($draft); same(false, $compiled->successful());
        same(true, in_array('post.message.category', array_column($compiled->diagnostics, 'code'), true));
    }
    foreach (Nicode\FormStudio\Translation\DefinitionTranslations::MESSAGES as $category) {
        $draft = $base;
        $draft['post_submit']['messages'] = [$category => 'Base {{form.name}}'];
        $draft['translations'] = ['es' => ['messages' => [$category => 'Traducido {{form.name}}']]];
        $compiled = compiler()->compile($draft); same(true, $compiled->successful());
        $definition = $compiled->spec->toArray();
        $fallback = [$category => 'Global fallback', 'unrelated' => 'Unchanged'];
        $localized = Nicode\FormStudio\Translation\DefinitionTranslations::resolve($definition, 'es-ES');
        $messages = Nicode\FormStudio\Translation\DefinitionTranslations::messages($localized, $fallback);
        same('Traducido Fixture', $messages[$category]); same('Unchanged', $messages['unrelated']);
        $messages = Nicode\FormStudio\Translation\DefinitionTranslations::messages(Nicode\FormStudio\Translation\DefinitionTranslations::resolve($definition, 'fr-FR'), $fallback);
        same('Base Fixture', $messages[$category]);
        unset($definition['post_submit']['messages'][$category]);
        same($fallback, Nicode\FormStudio\Translation\DefinitionTranslations::messages($definition, $fallback));
    }
});

test('compiler validates navigation destinations without executing actions or trusting arbitrary URLs', function (): void {
    $routes = static function (int $menu): string { if ($menu !== 4) { throw new RuntimeException('Private missing menu detail'); } return 'index.php?Itemid=4'; };
    $actions = new Nicode\FormStudio\Registry\ActionRegistry();
    $actions->register(new Nicode\FormStudio\Actions\NavigationAction(new Nicode\FormStudio\Security\RedirectPolicy(['thanks.example.com']), $routes));
    $compiler = new Nicode\FormStudio\Compiler\FormCompiler(registry(), $actions, new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry());
    foreach ([[], ['menu_id' => 0], ['menu_id' => '4'], ['menu_id' => 5], ['menu_id' => 4, 'url' => '/thanks'], ['url' => '//evil.test'], ['url' => '/%255cevil.test'], ['url' => 'https://evil.test'], ['url' => 'https://user@thanks.example.com']] as $config) {
        $draft = definition(); $draft['actions'] = [['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'type' => 'redirect', 'config' => $config]];
        $result = $compiler->compile($draft); same(false, $result->successful());
        same(['action.navigation'], array_column($result->diagnostics, 'code'));
        same(['/actions/0/config'], array_column($result->diagnostics, 'path'));
        same(false, str_contains(json_encode($result->diagnostics), 'Private missing menu detail'));
    }
    foreach ([['menu_id' => 4], ['url' => '/thanks'], ['url' => 'https://thanks.example.com/received']] as $config) {
        $draft = definition(); $draft['actions'] = [['uuid' => Nicode\FormStudio\Domain\Uuid::create(), 'type' => 'redirect', 'config' => $config]];
        same(true, $compiler->compile($draft)->successful());
    }
});
