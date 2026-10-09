<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Application;

use Nicode\FormStudio\Domain\FieldAddress;

/** Display only values already authorized by the historical submission reader. */
final class SubmissionColumns
{
    public static function project(array $answer, array $labels): array
    {
        $cells = []; $scopes = [];
        foreach ($labels as $field => $label) {
            $cells[$field] = ['present' => false, 'value' => null, 'label' => $label, 'option_label' => null, 'masked' => false];
        }
        foreach ($answer['masked'] ?? [] as $key) {
            $field = FieldAddress::fromKey($key)->field;
            if (isset($cells[$field])) { $cells[$field]['masked'] = true; }
        }
        foreach ($answer['values'] ?? [] as $key => $value) {
            $address = FieldAddress::fromKey($key); $field = $address->field;
            if (!isset($cells[$field]) || $cells[$field]['masked']) { continue; }
            $repeated = $address->instances !== [];
            if (isset($scopes[$field]) && $scopes[$field] !== $repeated) { throw new \InvalidArgumentException('Mixed field scopes in submission column.'); }
            $scopes[$field] = $repeated;
            $cell = &$cells[$field];
            $cell['label'] = $answer['labels'][$key] ?? $cell['label'];
            $option = $answer['option_labels'][$key] ?? null;
            if ($repeated) {
                if (!$cell['present']) { $cell['value'] = []; }
                $cell['value'][] = ['instance_path' => substr($key, 0, -37), 'value' => $value, 'option_label' => $option];
            } else {
                $cell['value'] = $value; $cell['option_label'] = $option;
            }
            $cell['present'] = true;
            unset($cell);
        }
        return $cells;
    }
}
