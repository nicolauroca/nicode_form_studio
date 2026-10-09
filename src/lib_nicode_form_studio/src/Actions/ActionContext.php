<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Actions;

use Nicode\FormStudio\Domain\FormSpec;

final readonly class ActionContext
{
    public function __construct(public FormSpec $spec, public array $values, public string $reference, public string $receivedAt, public array $optionLabels = [], public ?string $actionUuid = null, public string $locale = 'en-GB', public ?array $instances = null, public ?\Nicode\FormStudio\Contract\MailAttachmentResolverInterface $attachments = null)
    {
        if ($instances !== null) { (new \Nicode\FormStudio\Domain\RepeatedInstances($spec->toArray()['elements'], $instances))->bind($values); }
    }
    public function forAction(string $uuid): self { return new self($this->spec, $this->values, $this->reference, $this->receivedAt, $this->optionLabels, $uuid, $this->locale, $this->instances, $this->attachments); }
    public function definition(): array { return \Nicode\FormStudio\Translation\DefinitionTranslations::resolve($this->spec->toArray(), $this->locale); }
    public function emailTokens(): array
    {
        $definition = $this->definition();
        $tokens = ['form.name' => $definition['name'], 'form.uuid' => $definition['uuid'], 'submission.reference' => $this->reference, 'submission.date' => $this->receivedAt];
        $summary = [];
        $columns = $this->values; $labelColumns = $this->optionLabels;
        if ($this->instances !== null) {
            $ordered = []; $labels = [];
            foreach ((new \Nicode\FormStudio\Domain\RepeatedInstances($definition['elements'], $this->instances))->addresses() as $address) {
                $key = $address->key();
                if (array_key_exists($key, $this->values)) { $ordered[$key] = $this->values[$key]; $labels[$key] = $this->optionLabels[$key] ?? $this->values[$key]; }
            }
            $fields = array_column($definition['fields'], 'uuid');
            $columns = \Nicode\FormStudio\Export\FieldColumns::project($ordered, $fields);
            $labelColumns = \Nicode\FormStudio\Export\FieldColumns::project($labels, $fields);
        }
        foreach ($definition['fields'] as $field) {
            if (!($field['include_email'] ?? !($field['sensitive'] ?? false)) || $field['type'] === 'password') { continue; }
            $uuid = $field['uuid']; $value = $columns[$uuid] ?? null;
            $text = is_array($value) ? implode(', ', array_map(static fn ($item): string => is_scalar($item) ? (string) $item : '', $value)) : (is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? ''));
            $repeated = $this->instances !== null && !array_key_exists($uuid, $this->values) && is_array($value);
            if ($repeated) { $text = \Nicode\FormStudio\Domain\CanonicalJson::encode($value); }
            $tokens['field.' . $uuid . '.value'] = $text;
            $tokens['field.' . $uuid . '.label'] = $field['config']['label'] ?? $field['name'];
            $tokens['field.' . $uuid . '.option_label'] = $repeated ? \Nicode\FormStudio\Domain\CanonicalJson::encode($labelColumns[$uuid]) : ($this->optionLabels[$uuid] ?? $text);
            if (array_key_exists($uuid, $this->values) || $repeated) { $summary[] = ($field['config']['label'] ?? $field['name']) . ': ' . $text; }
        }
        $tokens['response.summary'] = implode("\n", $summary);
        return $tokens;
    }

    /** An action runs once when its condition matches a complete lexical row. */
    public function matchesCondition(array $condition, \Nicode\FormStudio\Rules\ConditionEvaluator $evaluator, array $datatypes): bool
    {
        if ($this->instances === null) { return $evaluator->matches($condition, $this->values, $datatypes); }
        $references = []; $visited = 0;
        $walk = static function (array $node, int $depth = 0) use (&$walk, &$references, &$visited): void {
            if ($depth > 64 || ++$visited > 10000) { throw new \InvalidArgumentException('Action condition budget exceeded.'); }
            if (isset($node['group'])) { foreach ($node['children'] ?? [] as $child) { $walk($child, $depth + 1); } }
            else { $references[] = $node['field'] ?? null; }
        };
        $walk($condition); $references = array_values(array_unique($references));
        $instances = new \Nicode\FormStudio\Domain\RepeatedInstances($this->spec->toArray()['elements'], $this->instances);
        $contexts = $instances->referenceContexts($references);
        if (count($contexts) * $visited > 10000) { throw new \InvalidArgumentException('Action evaluation budget exceeded.'); }
        foreach ($contexts as $origin) {
            $values = [];
            foreach ($references as $field) { $values[$field] = $this->values[$instances->resolve($origin, $field)->key()] ?? null; }
            if ($evaluator->matches($condition, $values, $datatypes)) { return true; }
        }
        return false;
    }
}
