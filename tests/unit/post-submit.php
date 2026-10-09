<?php
declare(strict_types=1);

test('redirects allow only configured internal and approved HTTPS destinations', function (): void {
    $policy = new Nicode\FormStudio\Security\RedirectPolicy(['thanks.example.com']);
    foreach (['/thank-you', 'index.php?Itemid=4', 'https://thanks.example.com/received'] as $url) { same($url, $policy->validate($url)); }
    foreach (['//evil.test', '/%2fevil.test', '/%255cevil.test', '/x%0d%0aLocation:evil', 'javascript:alert(1)', 'https://evil.test', 'https://user@thanks.example.com', 'https://thanks.example.com:8443'] as $url) { raises(InvalidArgumentException::class, fn () => $policy->validate($url)); }
});

test('success headings localize safe tokens and summaries require explicit eligible fields', function (): void {
    $draft = withSecond(definition(), 'text'); [$first, $second] = array_column($draft['fields'], 'uuid');
    $draft['fields'][0]['config']['label'] = 'First'; $draft['fields'][1]['config']['label'] = 'Second';
    $draft['post_submit'] = ['messages' => ['success_heading' => 'Thanks {{form.name}}'], 'summary_fields' => [$second, $first]];
    $draft['translations'] = ['es' => ['messages' => ['success_heading' => 'Gracias {{form.name}}'], 'fields' => [$first => ['label' => 'Primero']]]];
    $service = new Nicode\FormStudio\Submission\PostSubmit(new Nicode\FormStudio\Rules\ConditionEvaluator(Nicode\FormStudio\Registry\RuleOperatorRegistry::core()), registry(), new Nicode\FormStudio\Actions\TokenTemplate(), new Nicode\FormStudio\Security\RedirectPolicy(), ['success_heading' => 'Global']);
    $context = new Nicode\FormStudio\Actions\ActionContext(compiler()->compile($draft)->spec, [$first => '<b>answer</b>', $second => 'option-id'], 'reference', 'date', [$second => 'Option label'], locale: 'es-ES');
    $result = $service->result($context, ['status' => 'succeeded']);
    same('Gracias Fixture', $result['heading']);
    same([['label' => 'Second', 'value' => 'Option label'], ['label' => 'Primero', 'value' => '<b>answer</b>']], $result['summary']);
    foreach (['blocking_failure', 'pending', 'partial_failure'] as $status) {
        $result = $service->result($context, ['status' => $status]); same(false, isset($result['heading'])); same(false, isset($result['summary']));
    }
    unset($draft['translations'], $draft['post_submit']);
    $make = static fn (array $data) => new Nicode\FormStudio\Actions\ActionContext(new Nicode\FormStudio\Domain\FormSpec($data), [$first => 'private'], 'reference', 'date');
    $result = $service->result($make($draft), ['status' => 'succeeded']); same('Global', $result['heading']); same(false, isset($result['summary']));
    $draft['post_submit'] = ['messages' => ['success_heading' => ''], 'summary_fields' => [$first]];
    same('', $service->result($make($draft), ['status' => 'succeeded'])['heading']);
    foreach (['sensitive', 'password', 'file', 'multiple-files'] as $kind) {
        $unsafe = $draft;
        if ($kind === 'sensitive') { $unsafe['fields'][0]['sensitive'] = true; } else { $unsafe['fields'][0]['type'] = $kind; }
        $compiled = compiler()->compile($unsafe); same(false, $compiled->successful());
        same(true, in_array('post.summary', array_column($compiled->diagnostics, 'code'), true));
        same(false, isset($service->result($make($unsafe), ['status' => 'succeeded'])['summary']));
    }
});

test('confirmation summaries follow repeated row order and match sparse labels by address', function (): void {
    $draft = definition(); $field = $draft['fields'][0]['uuid']; $group = Nicode\FormStudio\Domain\Uuid::create();
    $one = Nicode\FormStudio\Domain\Uuid::create(); $two = Nicode\FormStudio\Domain\Uuid::create();
    $draft['elements'][0]['parent_uuid'] = $group;
    array_unshift($draft['elements'], ['uuid' => $group, 'type' => 'repeatable-group', 'repeat' => ['min' => 0, 'max' => 2]]);
    $draft['fields'][0]['config']['label'] = 'Answer'; $draft['post_submit']['summary_fields'] = [$field];
    $key = static fn (string $row): string => "$group/$row/$field";
    $context = new Nicode\FormStudio\Actions\ActionContext(compiler()->compile($draft)->spec, [$key($one) => 'one', $key($two) => 'two'], 'ref', 'date', [$key($one) => 'First label'], instances: [$group => [$two, $one]]);
    $service = new Nicode\FormStudio\Submission\PostSubmit(new Nicode\FormStudio\Rules\ConditionEvaluator(Nicode\FormStudio\Registry\RuleOperatorRegistry::core()), registry(), new Nicode\FormStudio\Actions\TokenTemplate(), new Nicode\FormStudio\Security\RedirectPolicy());
    same([['label' => 'Answer (1)', 'value' => 'two'], ['label' => 'Answer (2)', 'value' => 'First label']], $service->result($context, ['status' => 'succeeded'])['summary']);
    $empty = new Nicode\FormStudio\Actions\ActionContext($context->spec, [], 'ref', 'date', instances: [$group => []]);
    same([], $service->result($empty, ['status' => 'succeeded'])['summary']);
});
test('post-submit distinguishes receipt and processing and does not preserve sensitive fields', function (): void {
    $draft = definition(); $uuid = $draft['fields'][0]['uuid']; $draft['fields'][0]['sensitive'] = true;
    $draft['post_submit'] = ['behavior' => 'reset', 'preserve' => [$uuid], 'messages' => ['success' => 'Received {{submission.reference}}']];
    $context = new Nicode\FormStudio\Actions\ActionContext(compiler()->compile($draft)->spec, [$uuid => 'secret'], 'reference', 'date');
    $service = new Nicode\FormStudio\Submission\PostSubmit(new Nicode\FormStudio\Rules\ConditionEvaluator(Nicode\FormStudio\Registry\RuleOperatorRegistry::core()), registry(), new Nicode\FormStudio\Actions\TokenTemplate(), new Nicode\FormStudio\Security\RedirectPolicy());
    $result = $service->result($context, ['status' => 'succeeded']); same('Received reference', $result['message']); same([], $result['preserve']); same('reset', $result['behavior']);
    $result = $service->result($context, ['status' => 'blocking_failure', 'navigation' => ['redirect' => '/thanks']]); same(true, $result['accepted']); same(false, $result['processed']); same('keep', $result['behavior']); same(false, isset($result['redirect']));
});
