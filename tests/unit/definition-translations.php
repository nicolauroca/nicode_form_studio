<?php
declare(strict_types=1);
use Nicode\FormStudio\Translation\DefinitionTranslations;
use Nicode\FormStudio\Domain\{FormSpec, Uuid};
use Nicode\FormStudio\Actions\ActionContext;

test('dynamic translations fall back per property and preserve immutable published identity', function (): void {
    $draft = definition(); $field = $draft['fields'][0]['uuid'];
    $draft['base_language'] = 'en-GB'; $draft['fields'][0]['config']['placeholder'] = 'Original placeholder';
    $draft['translations'] = ['en-GB' => ['form' => ['name' => 'Base title'], 'fields' => [$field => ['help' => 'Base help']]], 'es' => ['fields' => [$field => ['label' => 'Nombre']]], 'es-ES' => ['form' => ['name' => 'Solicitud'], 'fields' => [$field => ['help' => 'Ayuda']]]];
    $compiled = compiler()->compile($draft); same(true, $compiled->successful()); $original = $compiled->spec; $hash = $original->hash;
    $localized = DefinitionTranslations::spec($original, 'ES-es')->toArray(); same('Solicitud', $localized['name']); same('Nombre', $localized['fields'][0]['config']['label']); same('Ayuda', $localized['fields'][0]['config']['help']); same('Original placeholder', $localized['fields'][0]['config']['placeholder']);
    same('Base help', DefinitionTranslations::resolve($draft, 'es-MX')['fields'][0]['config']['help']); same('Base title', DefinitionTranslations::resolve($draft, 'fr-FR')['name']);
    $context = new ActionContext($original, [$field => 'Ana'], Uuid::create(), '2026-01-01 12:00:00', locale: 'es-ES'); same('Solicitud', $context->emailTokens()['form.name']); same('Nombre', $context->emailTokens()['field.' . $field . '.label']); same('es-ES', $context->forAction(Uuid::create())->locale); same($hash, $context->spec->hash); same('Fixture', $original->toArray()['name']);
});

test('translations cannot change rules values recipients or reference missing identities', function (): void {
    $draft = definition(); $field = $draft['fields'][0]['uuid'];
    foreach ([['fields' => [$field => ['required' => false]]], ['rules' => []], ['fields' => [Uuid::create() => ['label' => 'Orphan']]], ['form' => ['name' => ['bad']]], ['fields' => [$field => ['label' => "bad\0label"]]]] as $translation) {
        $draft['translations'] = ['es-ES' => $translation]; same(true, in_array('translation.invalid', codes($draft), true));
    }
    $draft['translations'] = ['../../en' => ['form' => ['name' => 'Bad']]]; same(true, in_array('translation.invalid', codes($draft), true));
    $draft['translations'] = ['es-ES' => [], 'ES-es' => []]; same(true, in_array('translation.invalid', codes($draft), true));
    unset($draft['translations']); $draft['fields'][0]['options'] = [['uuid' => ['malformed'], 'value' => 'x', 'label' => 'X']]; same(true, in_array('translation.invalid', codes($draft), true));
});

test('translated option labels keep canonical values and email translation cannot change destinations', function (): void {
    $draft = definition(); $option = Uuid::create(); $action = Uuid::create();
    $draft['fields'][0]['options'] = [['uuid' => $option, 'value' => 'ES', 'label' => 'Spain', 'enabled' => true]];
    $draft['actions'] = [['uuid' => $action, 'type' => 'email_notification', 'config' => ['to' => ['owner@example.test'], 'subject' => 'Original', 'body_text' => 'Original body']]];
    $draft['translations'] = ['es-ES' => ['options' => [$option => ['label' => 'España']], 'actions' => [$action => ['subject' => 'Solicitud', 'body_text' => 'Respuesta']]]];
    same([], DefinitionTranslations::validate($draft)); $localized = DefinitionTranslations::resolve($draft, 'es-ES');
    same('España', $localized['fields'][0]['options'][0]['label']); same('ES', $localized['fields'][0]['options'][0]['value']); same(true, $localized['fields'][0]['options'][0]['enabled']); same(['owner@example.test'], $localized['actions'][0]['config']['to']); same('Solicitud', $localized['actions'][0]['config']['subject']);
    $draft['translations']['es-ES']['actions'][$action]['to'] = 'attacker@example.test'; same(true, DefinitionTranslations::validate($draft) !== []);
});

test('validation and outcome translations preserve message fallbacks and reject semantic overrides', function (): void {
    $draft = definition(); $uuid = $draft['fields'][0]['uuid'];
    $draft['fields'][0]['config']['validation_messages'] = ['required' => 'Required base', 'min_length' => 'Too short'];
    $draft['translations'] = ['es-ES' => ['validation' => [$uuid => ['required' => 'Indica tu respuesta']], 'messages' => ['success' => 'Gracias por responder a {{form.name}}']]];
    same(true, compiler()->compile($draft)->successful()); $localized = DefinitionTranslations::resolve($draft, 'es-ES');
    same('Indica tu respuesta', $localized['fields'][0]['config']['validation_messages']['required']); same('Too short', $localized['fields'][0]['config']['validation_messages']['min_length']);
    same('Gracias por responder a {{form.name}}', $localized['post_submit']['messages']['success']);
    $draft['translations']['es-ES']['messages']['redirect'] = 'https://example.test'; same(true, in_array('translation.invalid', codes($draft), true));
    unset($draft['translations']['es-ES']['messages']['redirect']); $draft['translations']['es-ES']['messages']['success'] = '{{field.secret.value}}'; same(false, compiler()->compile($draft)->successful());
});

test('conditional confirmation translations follow stable identities and preserve failure precedence', function (): void {
    $draft = definition(); $field = $draft['fields'][0]['uuid']; $first = Uuid::create(); $second = Uuid::create();
    $draft['post_submit'] = ['messages' => ['success' => 'Original success', 'processing_pending' => 'Waiting', 'action_blocking_failure' => 'Failed'], 'conditional_messages' => [
        ['uuid' => $first, 'condition' => ['field' => $field, 'operator' => 'equals', 'value' => 'yes'], 'message' => 'First'],
        ['uuid' => $second, 'condition' => ['field' => $field, 'operator' => 'not_empty'], 'message' => 'Second'],
    ]];
    $draft['translations'] = ['es-ES' => ['conditional_messages' => [$first => ['message' => 'Primero'], $second => ['message' => 'Segundo']]]];
    $compiled = compiler()->compile($draft); same(true, $compiled->successful());
    $service = new Nicode\FormStudio\Submission\PostSubmit(new Nicode\FormStudio\Rules\ConditionEvaluator(Nicode\FormStudio\Registry\RuleOperatorRegistry::core()), registry(), new Nicode\FormStudio\Actions\TokenTemplate(), new Nicode\FormStudio\Security\RedirectPolicy());
    $context = new ActionContext($compiled->spec, [$field => 'yes'], 'reference', 'date', locale: 'es-ES');
    same('Primero', $service->result($context, ['status' => 'succeeded'])['message']);
    same('Waiting', $service->result($context, ['status' => 'pending'])['message']);
    same('Failed', $service->result($context, ['status' => 'blocking_failure'])['message']);
    $draft['post_submit']['conditional_messages'] = array_reverse($draft['post_submit']['conditional_messages']);
    same('Segundo', $service->result(new ActionContext(compiler()->compile($draft)->spec, [$field => 'yes'], 'reference', 'date', locale: 'es-ES'), ['status' => 'succeeded'])['message']);
    $copy = (new Nicode\FormStudio\Domain\DefinitionRemapper())->duplicate($draft, Uuid::create());
    same('Primero', $copy['definition']['translations']['es-ES']['conditional_messages'][$copy['identities'][$first]]['message']);
    same(true, compiler()->compile($copy['definition'])->successful());
    $draft['post_submit']['conditional_messages'][1]['uuid'] = $second;
    same(true, in_array('translation.invalid', codes($draft), true));
    unset($draft['translations']);
    same(true, in_array('post.message.uuid', codes($draft), true));
});

test('translated email configuration renders safe tokens and is compiled before delivery', function (): void {
    $transport = new class implements Nicode\FormStudio\Contract\MailTransportInterface {
        public array $messages = [];
        public function send(Nicode\FormStudio\Actions\MailMessage $message): void { $this->messages[] = $message; }
    };
    $provider = new Nicode\FormStudio\Actions\EmailAction($transport, new Nicode\FormStudio\Actions\TokenTemplate());
    $actions = new Nicode\FormStudio\Registry\ActionRegistry(); $actions->register($provider);
    $compiler = new Nicode\FormStudio\Compiler\FormCompiler(registry(), $actions, new Nicode\FormStudio\Registry\DataSourceRegistry(), new Nicode\FormStudio\Registry\ValidatorRegistry());
    $draft = definition(); $field = $draft['fields'][0]['uuid']; $action = Uuid::create();
    $draft['actions'] = [['uuid' => $action, 'type' => 'email_notification', 'config' => ['to' => ['fixture@example.test'], 'subject' => 'Original', 'body_text' => 'Original']]];
    $draft['translations'] = ['es-ES' => ['form' => ['name' => 'Solicitud'], 'fields' => [$field => ['label' => 'Respuesta']], 'actions' => [$action => ['subject' => '{{form.name}} recibida', 'body_text' => '{{field.' . $field . '.label}}: {{field.' . $field . '.value}}']]]];
    $compiled = $compiler->compile($draft); same(true, $compiled->successful());
    $context = new ActionContext($compiled->spec, [$field => 'Sí'], 'reference', 'date', locale: 'es-ES');
    $provider->execute($context->definition()['actions'][0]['config'], $context->forAction($action));
    same('Solicitud recibida', $transport->messages[0]->subject); same('Respuesta: Sí', $transport->messages[0]->text); same(['fixture@example.test'], $transport->messages[0]->to);
    $draft['translations']['es-ES']['actions'][$action]['subject'] = "Subject\r\nBcc: other@example.test";
    same(false, $compiler->compile($draft)->successful()); same(1, count($transport->messages));
});

test('rule-provided option translations preserve selection values and duplication references', function (): void {
    $draft = withSecond(definition(), 'select'); [$parent, $child] = array_column($draft['fields'], 'uuid'); $option = Uuid::create();
    $draft['rules'] = [['uuid' => Uuid::create(), 'when' => ['field' => $parent, 'operator' => 'not_empty'], 'effects' => [['type' => 'change_options', 'target' => $child, 'value' => [['uuid' => $option, 'value' => 'yes', 'label' => 'Yes']]]]]];
    $draft['translations'] = ['es-ES' => ['options' => [$option => ['label' => 'Sí']]]];
    same(true, compiler()->compile($draft)->successful());
    $localized = DefinitionTranslations::resolve($draft, 'es-ES'); same('Sí', $localized['rules'][0]['effects'][0]['value'][0]['label']); same('yes', $localized['rules'][0]['effects'][0]['value'][0]['value']);
    $copy = (new Nicode\FormStudio\Domain\DefinitionRemapper())->duplicate($draft, Uuid::create());
    same($copy['identities'][$option], $copy['definition']['rules'][0]['effects'][0]['value'][0]['uuid']); same(['label' => 'Sí'], $copy['definition']['translations']['es-ES']['options'][$copy['identities'][$option]]);
    same(true, compiler()->compile($copy['definition'])->successful());
});
