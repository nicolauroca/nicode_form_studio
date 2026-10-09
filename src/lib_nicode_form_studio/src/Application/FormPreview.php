<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Compiler\FormCompiler;
use Nicode\FormStudio\Registry\FieldTypeRegistry;
use Nicode\FormStudio\Rendering\{FormRenderer, RenderContext};
use Nicode\FormStudio\Rules\RuleEngine;

/** Read-only draft compilation; no submission token, storage or Action execution. */
final readonly class FormPreview
{
    public function __construct(private FormAdministration $administration, private FormCompiler $compiler, private FieldTypeRegistry $fields, private RuleEngine $rules, private FormRenderer $renderer, private ?\Nicode\FormStudio\Contract\LifecycleEventsInterface $events = null, private ?\Nicode\FormStudio\Validation\ValidationEngine $validation = null) {}
    public function options(int $form, int $actor, int $revision, array $values, array $trustedContext = [], int $version = 0, ?array $declarations = null): array
    {
        $edit = $this->administration->edit($form, $actor);
        if ($version === 0 && (int) $edit['form']['draft_revision'] !== $revision) { throw new \Nicode\FormStudio\Domain\ConcurrentEdit('Preview draft changed.'); }
        $compiled = $this->compiler->compilePreview($version === 0 ? $edit['draft'] : $this->administration->snapshot($form, $version, $actor));
        if (!$compiled->successful()) { throw new \Nicode\FormStudio\Compiler\CompilationException($compiled->diagnostics); }
        $spec = \Nicode\FormStudio\Translation\DefinitionTranslations::spec($compiled->spec, $trustedContext['language'] ?? 'en-GB');
        $definition = $spec->toArray(); $fields = $definition['fields'];
        $repeated = in_array('repeatable-group', array_column($definition['elements'], 'type'), true);
        if ($repeated !== ($declarations !== null)) { throw new \InvalidArgumentException('Preview row declarations do not match the layout.'); }
        if ($declarations !== null) {
            $instances = new \Nicode\FormStudio\Domain\RepeatedInstances($definition['elements'], $declarations);
            $instances->bind($values);
            $byUuid = array_column($fields, null, 'uuid'); $fields = [];
            foreach ($instances->addresses() as $address) { $field = $byUuid[$address->field]; $field['uuid'] = $address->key(); $fields[] = $field; }
        }
        foreach ($fields as $field) {
            if (in_array($field['type'], ['file', 'multiple-files', 'password'], true)) { unset($values[$field['uuid']]); }
        }
        $validation = $this->validation ?? throw new \LogicException('Preview validation unavailable.');
        $context = array_replace($trustedContext, ['user_id' => $actor]);
        $state = ($declarations === null ? $validation->validate($spec, $values, [], $context) : $validation->validateInstances($spec, $declarations, $values, [], $context))->rules;
        $options = [];
        foreach ($fields as $field) {
            if (!isset($field['source']) || in_array($field['source']['type'], ['static', 'option_set'], true)) { continue; }
            $options[$field['uuid']] = [];
            if (!$state->states[$field['uuid']]['active']) { continue; }
            foreach ($state->states[$field['uuid']]['options'] as $option) {
                $public = ['value' => $option['value'], 'label' => $option['label'], 'enabled' => (bool) ($option['enabled'] ?? true)];
                if (array_key_exists('default', $option)) { $public['default'] = $option['default']; }
                $options[$field['uuid']][] = $public;
            }
        }
        return ['options' => $options];
    }
    public function changeRows(int $form, int $actor, int $revision, array $declarations, array $values, string $operation, string $group, ?string $row, string $instance, array $messages = [], array $trustedContext = [], int $version = 0): array
    {
        if (strlen($instance) > 128 || preg_match('/^nfs-preview-[a-zA-Z0-9_-]+$/D', $instance) !== 1) { throw new \InvalidArgumentException('Invalid preview instance.'); }
        $edit = $this->administration->edit($form, $actor);
        if ($version === 0 && (int) $edit['form']['draft_revision'] !== $revision) { throw new \Nicode\FormStudio\Domain\ConcurrentEdit('Preview draft changed.'); }
        $compiled = $this->compiler->compilePreview($version === 0 ? $edit['draft'] : $this->administration->snapshot($form, $version, $actor));
        if (!$compiled->successful()) { throw new \Nicode\FormStudio\Compiler\CompilationException($compiled->diagnostics); }
        $instances = new \Nicode\FormStudio\Domain\RepeatedInstances($compiled->spec->toArray()['elements'], $declarations);
        $values = $instances->bind($values);
        $address = \Nicode\FormStudio\Domain\FieldAddress::fromKey($group);
        $changed = match (true) {
            $operation === 'add' && $row === null => $instances->withAddedRow($address),
            $operation === 'remove' && $row !== null => $instances->withRemovedRow($address, $row),
            default => throw new \InvalidArgumentException('Invalid preview row operation.'),
        };
        $allowed = [];
        foreach ($changed->addresses() as $field) { $allowed[$field->key()] = true; }
        return $this->render($form, $actor, $messages, $trustedContext, $version, $changed->declarations(), array_intersect_key($values, $allowed), $instance, $revision);
    }

    public function render(int $form, int $actor, array $messages = [], array $trustedContext = [], int $version = 0, ?array $declarations = null, ?array $submitted = null, ?string $instance = null, ?int $revision = null): array
    {
        $edit = $this->administration->edit($form, $actor);
        if ($version === 0 && $revision !== null && (int) $edit['form']['draft_revision'] !== $revision) { throw new \Nicode\FormStudio\Domain\ConcurrentEdit('Preview draft changed.'); }
        $compilation = $this->compiler->compilePreview($version === 0 ? $edit['draft'] : $this->administration->snapshot($form, $version, $actor));
        $result = ['revision' => (int) $edit['form']['draft_revision'], 'version_id' => $version, 'diagnostics' => $compilation->diagnostics, 'html' => null];
        if (!$compilation->successful()) { return $result; }
        $localized = \Nicode\FormStudio\Translation\DefinitionTranslations::spec($compilation->spec, $trustedContext['language'] ?? 'en-GB');
        $result['locale'] = $trustedContext['language'] ?? 'en-GB'; $result['title'] = $localized->toArray()['name'];
        $messages = \Nicode\FormStudio\Translation\DefinitionTranslations::messages($localized->toArray(), $messages);
        $eventContext = ['form_uuid' => $edit['form']['uuid'], 'form_id' => $form, 'version_id' => $version, 'channel' => 'administrator-preview', 'locale' => $trustedContext['language'] ?? ''];
        $this->events?->emit('BeforeFormRender', $eventContext);
        $definition = $localized->toArray();
        $declarations ??= in_array('repeatable-group', array_column($definition['elements'], 'type'), true) ? \Nicode\FormStudio\Domain\RepeatedInstances::initial($definition['elements'])->declarations() : null;
        $presentation = new \Nicode\FormStudio\Rules\PresentationState($this->fields, $this->rules);
        $ruleContext = array_replace($trustedContext, ['user_id' => $actor]);
        $state = $declarations === null ? $presentation->evaluate($localized, $ruleContext) : $presentation->evaluateInstances($localized, $declarations, $ruleContext, submitted: $submitted, reset: $submitted !== null);
        $context = new RenderContext($instance ?? 'nfs-preview-' . bin2hex(random_bytes(8)), $form, 0, '/index.php', 'preview_token', '', $messages, true, previewRows: $declarations !== null);
        $result['html'] = $declarations === null ? $this->renderer->render($localized, $context, $state, captchaHtml: '<span>CAPTCHA</span>') : $this->renderer->renderInstances($localized, $declarations, $context, $state, captchaHtml: '<span>CAPTCHA</span>');
        $this->events?->emit('AfterFormRender', $eventContext);
        return $result;
    }
}
