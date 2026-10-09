<?php
declare(strict_types=1);

test('initial selections require an exact enabled possible option while rule choices remain available', function (): void {
    foreach (['select','multiselect'] as $type) {
        foreach (['default','constant'] as $mode) {
            $draft = definition(); $field = $draft['fields'][0]['uuid']; $draft['fields'][0]['type'] = $type;
            $draft['fields'][0]['options'] = [['value' => ' A ', 'label' => 'Allowed'], ['value' => 'disabled', 'label' => 'Disabled', 'enabled' => false]];
            $set = static function (array &$draft, string $value) use ($type,$mode): void {
                $value = $type === 'multiselect' ? [$value] : $value;
                if ($mode === 'default') { $draft['fields'][0]['config']['default'] = $value; }
                else { $draft['fields'][0]['prefill'] = ['type' => 'constant','value' => $value]; }
            };
            foreach (['A','disabled','unknown'] as $value) {
                $set($draft,$value); $result = compiler()->compile($draft);
                same(['field.initial.option'],array_column($result->diagnostics,'code'));
                same(['/fields/0' . ($mode === 'default' ? '/config/default' : '/prefill/value')],array_column($result->diagnostics,'path'));
            }
            $set($draft,' A '); same(true,compiler()->compile($draft)->successful());
            $set($draft,'rule-choice');
            $draft['rules'] = [['uuid' => Nicode\FormStudio\Domain\Uuid::create(),'when' => ['field' => $field,'operator' => 'not_empty'],'effects' => [['type' => 'change_options','target' => $field,'value' => [['value' => 'rule-choice','label' => 'Rule choice']]]]]];
            // Use a separate trigger to avoid an option dependency cycle.
            $draft = withSecond($draft); $draft['rules'][0]['when']['field'] = $draft['fields'][1]['uuid'];
            same(true,compiler()->compile($draft)->successful());
            $draft['rules'][0]['enabled'] = false;
            same(['field.initial.option'],array_column(compiler()->compile($draft)->diagnostics,'code'));
        }
    }
});

test('initial selection checks use embedded source snapshots and defer custom option providers', function (): void {
    $sources = new Nicode\FormStudio\Registry\DataSourceRegistry(); $sources->register(new Nicode\FormStudio\DataSource\StaticDataSource());
    $sources->register(new class implements Nicode\FormStudio\Contract\DataSourceInterface {
        public function id(): string { return 'dynamic_choices'; }
        public function version(): string { return '1.0.0'; }
        public function metadata(): array { return ['id' => $this->id(),'version' => $this->version()]; }
        public function validateConfiguration(array $configuration,string $path): array { return []; }
        public function options(array $configuration,array $inputs,array $trustedContext): array { throw new RuntimeException('Compiler must not resolve dynamic options.'); }
    });
    $compiler = new Nicode\FormStudio\Compiler\FormCompiler(registry(),new Nicode\FormStudio\Registry\ProviderRegistry(),$sources,new Nicode\FormStudio\Registry\ProviderRegistry());
    $draft = definition(); $draft['fields'][0]['type'] = 'select'; $draft['fields'][0]['config']['default'] = 'dynamic';
    $draft['fields'][0]['source'] = ['type' => 'static','config' => ['options' => [['value' => 'snapshot','label' => 'Snapshot']]]];
    same(['field.initial.option'],array_column($compiler->compile($draft)->diagnostics,'code'));
    $draft['fields'][0]['config']['default'] = 'snapshot'; same(true,$compiler->compile($draft)->successful());
    $draft['fields'][0]['source'] = ['type' => 'dynamic_choices','config' => []];
    same(true,$compiler->compile($draft)->successful());
});
