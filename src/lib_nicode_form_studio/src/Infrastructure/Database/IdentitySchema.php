<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Infrastructure\Database;

/** Idempotent pre-release migration; binary strings retain all UTF-8 bytes. */
final class IdentitySchema
{
    public static function upgrade(\Joomla\Database\DatabaseInterface $db): void
    {
        if ($db->getServerType() !== 'mysql') { return; }
        foreach (['field_options' => ['option_value', false], 'option_set_items' => ['option_value', false], 'submission_index' => ['value_keyword', true]] as $table => [$column, $nullable]) {
            $name = $db->replacePrefix('#__nicode_form_studio_' . $table);
            $columns = $db->getTableColumns($name, false);
            if (strtolower($columns[$column]->Type ?? '') === 'varbinary(1020)') { continue; }
            $db->setQuery('ALTER TABLE ' . $db->quoteName($name) . ' MODIFY ' . $db->quoteName($column) . ' VARBINARY(1020)' . ($nullable ? ' NULL' : ' NOT NULL'))->execute();
        }
    }
}
