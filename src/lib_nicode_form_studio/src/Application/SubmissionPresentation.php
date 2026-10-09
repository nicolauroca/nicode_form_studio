<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

/** Historical layout projection contains display labels, never authoring configuration. */
final class SubmissionPresentation
{
    public static function layout(array $definition, array $values, array $masked, ?array $declarations = null): array
    {
        if ($declarations !== null) {
            $instances = new \Nicode\FormStudio\Domain\RepeatedInstances($definition['elements'], $declarations);
            $instances->bind($values + array_fill_keys($masked, null));
            $instances->elementAddresses(); // Enforce the total expansion budget before rendering.
            $declarations = $instances->declarations();
        }
        $children = []; $fields = array_column($definition['fields'], null, 'uuid'); $hidden = array_fill_keys($masked, true);
        foreach ($definition['elements'] as $element) { $children[$element['parent_uuid'] ?? 'root'][] = $element; }
        $walk = static function (string $parent, int $depth = 0, string $scope = '') use (&$walk, $children, $fields, $values, $hidden, $declarations): array {
            if ($depth > 64) { throw new \DomainException('Historical layout exceeds depth limit.'); }
            $result = [];
            foreach ($children[$parent] ?? [] as $element) {
                $definitionUuid = $element['uuid']; $uuid = $scope . $definitionUuid;
                if ($element['type'] === 'field') {
                    $field = $fields[$definitionUuid] ?? null;
                    if (!$field || $field['type'] === 'password' || (!array_key_exists($uuid, $values) && !isset($hidden[$uuid]))) { continue; }
                    $result[] = ['kind' => 'field', 'uuid' => $uuid, 'label' => $field['config']['label'] ?? $field['name'], 'masked' => isset($hidden[$uuid])];
                } elseif ($element['type'] === 'repeatable-group' && $declarations !== null) {
                    $rows = [];
                    foreach ($declarations[$uuid] ?? [] as $position => $instance) {
                        $nested = $walk($definitionUuid, $depth + 1, $uuid . '/' . $instance . '/');
                        if ($nested !== []) { $rows[] = ['kind' => 'group', 'label' => ($element['title'] ?? '') . ' · ' . ($position + 1), 'children' => $nested]; }
                    }
                    if ($rows !== []) { $result[] = ['kind' => 'group', 'label' => $element['title'] ?? '', 'children' => $rows]; }
                } else {
                    $nested = $walk($definitionUuid, $depth + 1, $scope);
                    if ($nested !== []) { $result[] = ['kind' => 'group', 'label' => $element['title'] ?? '', 'children' => $nested]; }
                }
            }
            return $result;
        };
        return $walk('root');
    }
}
