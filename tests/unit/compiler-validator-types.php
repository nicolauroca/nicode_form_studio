<?php
declare(strict_types=1);

test('compiler rejects incompatible core comparisons at their original scope', function (): void {
    $compiler = new Nicode\FormStudio\Compiler\FormCompiler(registry(), new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry(), Nicode\FormStudio\Registry\ValidatorRegistry::core());
    $cases = [
        ['integer', 'text', 'equals', false], ['text', 'integer', 'equals', false],
        ['integer', 'decimal', 'greater', true], ['decimal', 'integer', 'range', true],
        ['date', 'date', 'before', true], ['date', 'datetime-local', 'after', false],
        ['integer', 'integer', 'before', false], ['text', 'text', 'greater', false],
        ['email', 'password', 'confirmation', true], ['time', 'time', 'range', true],
        ['multiselect', 'select', 'equals', false], ['multiselect', 'checkbox-group', 'equals', true],
        ['multiselect', 'multiselect', 'range', false], ['file', 'file', 'equals', false],
        ['file', 'integer', 'at_least_one', true], ['date', 'checkbox', 'exactly_n', true],
    ];
    foreach ($cases as [$left, $right, $type, $valid]) {
        foreach ([false, true] as $fieldScope) {
            $draft = withSecond(definition(), $right); $draft['fields'][0]['type'] = $left;
            foreach ($draft['fields'] as &$field) {
                if ($field['type'] === 'file') { $field['config'] += ['extensions' => ['txt'], 'mime_types' => ['text/plain'], 'max_bytes' => 1024]; }
            }
            unset($field);
            $validator = ['type' => $type, 'config' => ['fields' => array_column($draft['fields'], 'uuid'), ...($type === 'exactly_n' ? ['count' => 1] : [])]];
            if ($fieldScope) { $draft['fields'][1]['validators'] = [$validator]; }
            else { $draft['validators'] = [$validator]; }
            $result = $compiler->compile($draft);
            if ($valid !== $result->successful()) { throw new RuntimeException("$left / $right / $type: " . json_encode($result->diagnostics)); }
            same($valid, $result->successful());
            $errors = array_values(array_filter($result->diagnostics, static fn ($error): bool => $error->code === 'validator.compatibility'));
            same($valid ? 0 : 1, count($errors));
            if (!$valid) { same($fieldScope ? '/fields/1/validators/0' : '/validators/0', $errors[0]->path); }
        }
    }
});

test('custom validator with a core identifier retains its own comparison contract', function (): void {
    $validators = new Nicode\FormStudio\Registry\ProviderRegistry();
    $validators->register(new class implements Nicode\FormStudio\Contract\ValidatorInterface {
        public function id(): string { return 'equals'; }
        public function version(): string { return '1.0.0'; }
        public function metadata(): array { return ['id' => $this->id(), 'version' => $this->version()]; }
        public function validateConfiguration(array $configuration, string $path): array { return []; }
        public function validate(array $values, array $configuration, array $datatypes): array { return []; }
    });
    $draft = withSecond(definition(), 'integer');
    $draft['validators'] = [['type' => 'equals', 'config' => ['fields' => array_column($draft['fields'], 'uuid')]]];
    $compiler = new Nicode\FormStudio\Compiler\FormCompiler(registry(), new Nicode\FormStudio\Registry\ProviderRegistry(), new Nicode\FormStudio\Registry\ProviderRegistry(), $validators);
    same(true, $compiler->compile($draft)->successful());
});
