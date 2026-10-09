<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Rules;

use Nicode\FormStudio\Compiler\FormCompiler;
use Nicode\FormStudio\Contract\RuleEffectInterface;
use Nicode\FormStudio\Domain\Diagnostic;

final readonly class CoreEffect implements RuleEffectInterface
{
    public function __construct(private string $identifier)
    {
        if (!in_array($identifier, FormCompiler::EFFECTS, true)) { throw new \InvalidArgumentException('Unknown core effect.'); }
    }
    public function id(): string { return $this->identifier; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['id' => $this->id(), 'version' => $this->version()]; }
    public function validateConfiguration(array $configuration, string $path): array
    {
        if (in_array($this->id(), ['set_value', 'change_default', 'change_options', 'filter_options'], true) && !array_key_exists('value', $configuration)) {
            return [new Diagnostic('effect.value', $path, 'Effect value is required.')];
        }
        if (in_array($this->id(), ['change_options', 'filter_options'], true)) {
            $value = $configuration['value'];
            if (!is_array($value) || !array_is_list($value)) { return [new Diagnostic('effect.options', $path, 'Expected an option list.')]; }
            $seen = [];
            foreach ($value as $i => $item) {
                if ($this->id() === 'filter_options' ? !is_string($item) : (!is_array($item) || !is_string($item['value'] ?? null) || !is_string($item['label'] ?? null))) {
                    return [new Diagnostic('effect.options', $path, 'Invalid option entry.')];
                }
                if ($this->id() === 'change_options') {
                    if (isset($seen[$item['value']])) { return [new Diagnostic('effect.option.duplicate', "$path/value/$i", 'Duplicate option identity.')]; }
                    $seen[$item['value']] = true;
                    foreach (['enabled', 'default'] as $flag) {
                        if (array_key_exists($flag, $item) && !is_bool($item[$flag])) { return [new Diagnostic('effect.option.flag', "$path/value/$i/$flag", 'Option flags must be booleans.')]; }
                    }
                }
            }
        }
        return [];
    }
    public function apply(array $state, array $configuration): array
    {
        switch ($this->id()) {
            case 'show': $state['visible'] = true; break;
            case 'hide': $state['visible'] = false; break;
            case 'enable': $state['enabled'] = true; break;
            case 'disable': $state['enabled'] = false; break;
            case 'required': $state['required'] = true; break;
            case 'optional': $state['required'] = false; break;
            case 'set_value': $state['value'] = $configuration['value']; break;
            case 'clear_value': $state['value'] = null; break;
            case 'change_default': if ($state['value'] === null) { $state['value'] = $configuration['value']; } break;
            case 'change_options': $state['options'] = $configuration['value']; break;
            case 'filter_options':
                $state['options'] = array_values(array_filter($state['options'], static fn (array $option): bool => in_array($option['value'], $configuration['value'], true)));
                break;
        }
        return $state;
    }
}
