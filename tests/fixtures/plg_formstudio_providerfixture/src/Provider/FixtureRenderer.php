<?php
declare(strict_types=1);
namespace NicodeFixture\Plugin\FormStudios\ProviderFixture\Provider;

final readonly class FixtureRenderer implements \Nicode\FormStudio\Contract\FieldRendererInterface
{
    public function render(array $field, string $instance, mixed $value, array $errors = []): string
    {
        $field['type'] = 'text';
        return '<div class="fixture-uppercase">' . (new \Nicode\FormStudio\Rendering\CoreFieldRenderer())->render($field, $instance, $value, $errors) . '</div>';
    }
}
