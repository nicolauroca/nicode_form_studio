<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Rendering;

use Nicode\FormStudio\Contract\FieldRendererInterface;

final class CoreFieldRenderer implements FieldRendererInterface
{
    public function render(array $field, string $instance, mixed $value, array $errors = []): string
    {
        $type = $field['type']; $config = \Nicode\FormStudio\Field\CommonConfiguration::effective($type, $field['config'] ?? []);
        $id = $instance . '-' . $field['uuid']; $name = 'nfs[' . $field['uuid'] . ']';
        $multiple = in_array($type, ['multiselect', 'checkbox-group', 'multiple-files'], true);
        $attributes = ['id' => $id, 'name' => $name . ($multiple ? '[]' : ''), 'data-nfs-input' => $field['uuid'],
            'required' => $config['required'] ?? false, 'disabled' => $config['disabled'] ?? false, 'readonly' => $config['readonly'] ?? false,
            'aria-invalid' => $errors !== [] ? 'true' : null, 'aria-describedby' => $id . '-help' . ($errors !== [] ? ' ' . $id . '-error' : '')];
        if (($config['readonly'] ?? false) && in_array($type, ['select', 'multiselect', 'radio', 'checkbox-group', 'button-group', 'checkbox', 'toggle', 'yes-no', 'consent', 'range', 'color', 'file', 'multiple-files'], true)) {
            // These native controls do not implement the HTML readonly attribute.
            $attributes['disabled'] = true; $attributes['readonly'] = false;
        }
        foreach (['placeholder', 'autocomplete', 'inputmode', 'min', 'max', 'step', 'pattern'] as $key) { if (isset($config[$key])) { $attributes[$key] = $config[$key]; } }
        if (!isset($attributes['step']) && in_array($type, ['decimal', 'currency', 'number'], true)) { $attributes['step'] = 'any'; }
        if (!isset($attributes['step']) && in_array($type, ['time', 'datetime-local'], true)) { $attributes['step'] = '1'; }
        // Length limits count Unicode code points in both validators. Native
        // minlength/maxlength count UTF-16 units and would truncate valid input.
        if ($type === 'hidden' || $type === 'system') { return '<input' . Html::attributes(['type' => 'hidden', 'value' => is_scalar($value) ? $value : '', ...$attributes]) . '>'; }
        $label = Html::escape($config['label'] ?? $field['name']);
        $help = '<div class="nfs-help" id="' . Html::escape($id . '-help') . '">';
        foreach (['description', 'help'] as $property) { if (($config[$property] ?? '') !== '') { $help .= '<div>' . Html::escape($config[$property]) . '</div>'; } }
        $help .= '</div>';
        $error = '<div' . Html::attributes(['class'=>'nfs-error', 'id'=>$id . '-error', 'data-nfs-error'=>$field['uuid'], 'hidden'=>$errors === []]) . '>' . Html::escape(implode(' ', $errors)) . '</div>';
        $required = ($config['required'] ?? false) ? ' <span aria-hidden="true">*</span>' : '';
        if (in_array($type, ['radio', 'checkbox-group', 'button-group'], true)) {
            $html = '<fieldset class="nfs-options"><legend>' . $label . $required . '</legend><div data-nfs-choices>';
            foreach ($field['options'] ?? [] as $i => $option) {
                if (!($option['enabled'] ?? true)) { continue; }
                $optionId = $id . '-' . $i;
                $choiceAttributes = array_replace($attributes, ['id' => $optionId, 'type' => $type === 'checkbox-group' ? 'checkbox' : 'radio', 'value' => $option['value'], 'checked' => $multiple ? in_array($option['value'], is_array($value) ? $value : [], true) : $value === $option['value']]);
                // A checkbox group requires a group count check, not every checkbox.
                if ($multiple) { $choiceAttributes['required'] = false; }
                $html .= '<div><input' . Html::attributes($choiceAttributes) . '><label for="' . Html::escape($optionId) . '">' . Html::escape($option['label']) . '</label></div>';
            }
            return $html . '</div>' . $help . $error . '</fieldset>';
        }
        if (in_array($type, ['checkbox', 'toggle', 'yes-no', 'consent'], true)) {
            $input = '<input' . Html::attributes([...$attributes, 'type' => 'checkbox', 'value' => '1', 'checked' => $value === true]) . '>';
            return $input . '<label for="' . Html::escape($id) . '">' . $label . $required . '</label>' . $help . $error;
        }
        $input = '';
        if ($type === 'textarea') { $input = '<textarea' . Html::attributes($attributes) . '>' . Html::escape($value) . '</textarea>'; }
        elseif (in_array($type, ['select', 'multiselect'], true)) {
            $input = '<select' . Html::attributes([...$attributes, 'multiple' => $multiple]) . '>';
            if (!$multiple) { $input .= '<option value=""></option>'; }
            foreach ($field['options'] ?? [] as $option) {
                if (!($option['enabled'] ?? true)) { continue; }
                $selected = $multiple ? in_array($option['value'], is_array($value) ? $value : [], true) : $value === $option['value'];
                $input .= '<option' . Html::attributes(['value' => $option['value'], 'selected' => $selected]) . '>' . Html::escape($option['label']) . '</option>';
            }
            $input .= '</select>';
        } elseif (in_array($type, ['file', 'multiple-files'], true)) {
            $extensions = $config['extensions'] ?? [];
            $input = '<input' . Html::attributes([...$attributes, 'type' => 'file', 'multiple' => $multiple, 'accept' => implode(',', array_map(static fn ($ext) => '.' . $ext, $extensions))]) . '>';
        } elseif ($type === 'calculated') {
            $input = '<output' . Html::attributes(['id' => $id, 'data-nfs-output' => $field['uuid']]) . '>' . Html::escape($value) . '</output>';
        } else {
            $inputType = match ($type) { 'integer', 'decimal', 'currency', 'number' => 'number', 'telephone' => 'tel', default => $type };
            $input = '<input' . Html::attributes([...$attributes, 'type' => $inputType, 'value' => $type === 'password' ? '' : $value]) . '>';
        }
        return '<label for="' . Html::escape($id) . '">' . $label . $required . '</label>' . $input . $help . $error;
    }
}
