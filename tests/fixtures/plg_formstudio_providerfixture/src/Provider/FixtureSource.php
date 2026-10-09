<?php
declare(strict_types=1);
namespace NicodeFixture\Plugin\FormStudios\ProviderFixture\Provider;
use Nicode\FormStudio\Contract\DataSourceInterface;
use Nicode\FormStudio\Domain\Diagnostic;
final readonly class FixtureSource implements DataSourceInterface, \Nicode\FormStudio\Contract\PortableProviderInterface
{
    public function id(): string { return 'fixture.department'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['id' => $this->id(), 'version' => $this->version(), 'cache' => false, 'context_keys' => [], 'dependency_parameters' => ['country'], 'config_schema' => ['prefix' => 'string'], 'capabilities' => ['options'], 'lifecycle' => 'request', 'security' => 'synthetic fixed values only']; }
    public function exportConfiguration(array $configuration, string $mode): array { return array_intersect_key($configuration, array_flip(['prefix', 'country'])); }
    public function validateConfiguration(array $configuration, string $path): array
    {
        return is_string($configuration['prefix'] ?? null) && strlen($configuration['prefix']) <= 30 ? [] : [new Diagnostic('fixture.prefix', $path, 'A short prefix is required.')];
    }
    public function options(array $configuration, array $inputs, array $trustedContext): array
    {
        if (isset($configuration['country'])) {
            if (($inputs[$configuration['country']] ?? null) !== 'ES') { return []; }
            $options = [['value' => 'MD', 'label' => 'Madrid', 'private_marker' => 'not-public'], ['value' => 'BC', 'label' => 'Barcelona']];
            if (($configuration['defaults'] ?? false) === true) { foreach ($options as &$option) { $option['default'] = true; } unset($option); }
            return $options;
        }
        return [['value' => 'support', 'label' => $configuration['prefix'] . ' Support']];
    }
}
