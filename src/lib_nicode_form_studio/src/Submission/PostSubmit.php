<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Submission;

use Nicode\FormStudio\Actions\ActionContext;
use Nicode\FormStudio\Actions\TokenTemplate;
use Nicode\FormStudio\Rules\ConditionEvaluator;
use Nicode\FormStudio\Registry\FieldTypeRegistry;
use Nicode\FormStudio\Security\RedirectPolicy;

final readonly class PostSubmit
{
    public function __construct(private ConditionEvaluator $conditions, private FieldTypeRegistry $fields, private TokenTemplate $templates, private RedirectPolicy $redirects, private array $globalMessages = []) {}
    public function result(ActionContext $context, array $actionResult): array
    {
        $spec = $context->definition(); $config = $spec['post_submit'] ?? [];
        $blocking = ($actionResult['status'] ?? '') === 'blocking_failure';
        $pending = ($actionResult['status'] ?? '') === 'pending';
        $category = $pending ? 'processing_pending' : ($blocking ? 'action_blocking_failure' : (($actionResult['status'] ?? '') === 'succeeded' ? 'success' : 'action_partial_failure'));
        $fallback = $blocking ? 'Your response was received, but processing could not be completed. Please keep your reference.' : 'Your response has been received.';
        $template = $config['messages'][$category] ?? $this->globalMessages[$category] ?? $fallback;
        $datatypes = [];
        foreach ($spec['fields'] as $field) { $datatypes[$field['uuid']] = $this->fields->get($field['type'])->metadata()['datatype']; }
        if (!$blocking && !$pending) {
            foreach ($config['conditional_messages'] ?? [] as $candidate) {
                if ($context->matchesCondition($candidate['condition'], $this->conditions, $datatypes)) { $template = $candidate['message']; break; }
            }
        }
        // Confirmation messages deliberately expose no field tokens by default.
        $message = $this->templates->render($template, ['form.name' => $spec['name'], 'submission.reference' => $context->reference, 'submission.date' => $context->receivedAt]);
        $behavior = $blocking || $pending ? 'keep' : ($config['behavior'] ?? 'keep');
        if (!in_array($behavior, ['keep', 'hide', 'reset'], true)) { throw new \DomainException('Invalid post-submit behavior.'); }
        $preservable = array_column(array_filter($spec['fields'], static fn (array $field): bool => !in_array($field['type'], ['password', 'file', 'multiple-files'], true) && !($field['sensitive'] ?? false)), 'uuid');
        $result = ['accepted' => true, 'processed' => !$blocking && !$pending, 'category' => $category, 'message' => $message, 'behavior' => $behavior, 'preserve' => array_values(array_intersect($config['preserve'] ?? [], $preservable)), 'errors' => []];
        if ($category === 'success') {
            $heading = $config['messages']['success_heading'] ?? $this->globalMessages['success_heading'] ?? 'Thank you';
            $result['heading'] = $this->templates->render($heading, ['form.name' => $spec['name'], 'submission.reference' => $context->reference, 'submission.date' => $context->receivedAt]);
            $selected = array_values(array_intersect($config['summary_fields'] ?? [], $preservable));
            if ($selected !== []) {
                $addresses = $context->instances === null
                    ? array_map(\Nicode\FormStudio\Domain\FieldAddress::fromKey(...), array_keys($context->values))
                    : (new \Nicode\FormStudio\Domain\RepeatedInstances($spec['elements'], $context->instances))->addresses();
                $rows = [];
                foreach ($addresses as $address) {
                    $key = $address->key();
                    if (!in_array($address->field, $selected, true) || !array_key_exists($key, $context->values)) { continue; }
                    $rows[$address->field][] = ['repeated' => $address->instances !== [], 'value' => $context->optionLabels[$key] ?? $context->values[$key]];
                }
                $fields = array_column($spec['fields'], null, 'uuid'); $result['summary'] = [];
                $display = static fn (mixed $value): string => is_array($value) ? \Nicode\FormStudio\Domain\CanonicalJson::encode($value) : (is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? ''));
                foreach (array_unique($selected) as $uuid) {
                    $label = $fields[$uuid]['config']['label'] ?? $fields[$uuid]['name'];
                    foreach ($rows[$uuid] ?? [] as $index => $row) {
                        $result['summary'][] = ['label' => $label . ($row['repeated'] ? ' (' . ($index + 1) . ')' : ''), 'value' => $display($row['value'])];
                    }
                }
            }
        }
        if ($config['show_reference'] ?? true) { $result['reference'] = $context->reference; }
        if (!$blocking && !$pending && isset($actionResult['navigation']['redirect'])) { $result['redirect'] = $this->redirects->validate($actionResult['navigation']['redirect']); }
        return $result;
    }
}
