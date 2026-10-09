<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Field;

/** Shared presentation properties; providers retain their own semantic schema. */
final class CommonConfiguration
{
    public static function effective(string $type, array $configuration): array
    {
        if ($type === 'range') {
            foreach (['min' => '0', 'max' => '100', 'step' => '1'] as $key => $value) { $configuration[$key] ??= $value; }
        }
        return $configuration;
    }
    public static function properties(): array
    {
        return ['label' => ['type' => 'string'], 'admin_label' => ['type' => 'string'], 'description' => ['type' => 'string'], 'help' => ['type' => 'string'], 'css_class' => ['type' => 'string'], 'visible' => ['type' => 'boolean', 'default' => true], 'required' => ['type' => 'boolean'], 'readonly' => ['type' => 'boolean'], 'disabled' => ['type' => 'boolean']];
    }
    public static function validClasses(mixed $classes): bool
    {
        return is_string($classes) && ($classes === '' || preg_match('/^nfs-custom-[a-z][a-z0-9_-]{0,47}(?: nfs-custom-[a-z][a-z0-9_-]{0,47}){0,7}$/D', $classes) === 1);
    }
}
