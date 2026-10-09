<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Field;

use Nicode\FormStudio\Registry\FieldTypeRegistry;

final class CoreFieldTypes
{
    public static function register(FieldTypeRegistry $registry): void
    {
        $registry->register(new FileFieldType());
        $registry->register(new FileFieldType(true));
        foreach (['text', 'textarea', 'email', 'telephone', 'url', 'search', 'password', 'hidden', 'color', 'calculated', 'system'] as $id) {
            $registry->register(new ScalarFieldType($id, 'text', in_array($id, ['password', 'system'], true) ? null : ($id === 'textarea' ? 'text' : 'keyword')));
        }
        foreach (['integer', 'decimal', 'number', 'currency', 'range'] as $id) {
            $registry->register(new ScalarFieldType($id, $id === 'integer' ? 'integer' : 'decimal', $id === 'integer' ? 'integer' : 'decimal'));
        }
        foreach (['date' => 'date', 'time' => 'time', 'datetime-local' => 'datetime', 'month' => 'month', 'week' => 'week'] as $id => $type) {
            $registry->register(new ScalarFieldType($id, $type, in_array($type, ['date', 'datetime'], true) ? $type : 'keyword'));
        }
        foreach (['select', 'multiselect', 'radio', 'checkbox-group', 'button-group'] as $id) {
            $registry->register(new ScalarFieldType($id, 'selection', 'keyword', in_array($id, ['multiselect', 'checkbox-group'], true)));
        }
        foreach (['checkbox', 'toggle', 'yes-no', 'consent'] as $id) {
            $registry->register(new ScalarFieldType($id, 'boolean', 'boolean'));
        }
    }
}
