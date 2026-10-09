<?php
declare(strict_types=1);
namespace NicodeFixture\Plugin\FormStudios\ProviderFixture\Provider;

final readonly class FixtureField implements \Nicode\FormStudio\Contract\FieldTypeInterface, \Nicode\FormStudio\Contract\BrowserProviderInterface, \Nicode\FormStudio\Contract\PortableProviderInterface
{
    private \Nicode\FormStudio\Field\ScalarFieldType $base;
    public function __construct() { $this->base = new \Nicode\FormStudio\Field\ScalarFieldType('text', 'text', 'keyword'); }
    public function id(): string { return 'fixture.upper'; }
    public function version(): string { return '1.2.0'; }
    public function metadata(): array { return array_replace($this->base->metadata(), ['id' => $this->id(), 'version' => $this->version(), 'renderer' => $this->id()]); }
    public function browser(): array { return ['asset' => 'fixture.browser', 'public_config' => [], 'styles' => ['fixture.browser']]; }
    public function exportConfiguration(array $configuration, string $mode): array { return array_intersect_key($configuration, array_flip(['label', 'help', 'required', 'placeholder', 'min_length', 'max_length', 'pattern', 'trim', 'readonly', 'disabled', 'default'])); }
    public function validateConfiguration(array $configuration, string $path): array { return $this->base->validateConfiguration($configuration, $path); }
    public function normalize(mixed $value, array $configuration): mixed { $normalized = $this->base->normalize($value, $configuration); return $normalized === null ? null : mb_strtoupper($normalized, 'UTF-8'); }
    public function validate(mixed $value, array $configuration): array { return $this->base->validate($value, $configuration); }
    public function serialize(mixed $value): mixed { return $value; }
    public function indexType(): ?string { return 'keyword'; }
    public function multiple(): bool { return false; }
}
