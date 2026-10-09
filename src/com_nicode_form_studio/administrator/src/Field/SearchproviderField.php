<?php
declare(strict_types=1);
namespace Nicode\Component\FormStudio\Administrator\Field;
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Nicode\FormStudio\Registry\SearchProviderRegistry;

final class SearchproviderField extends ListField
{
    protected $type = 'Searchprovider';
    protected function getOptions()
    {
        $app = Factory::getApplication(); $options = []; $found = false;
        $registry = $app->bootComponent('com_nicode_form_studio')->runtime($app)->get(SearchProviderRegistry::class);
        foreach ($registry->metadata() as $id => $metadata) {
            if (($metadata['keyset'] ?? false) !== true || ($metadata['historical_privacy'] ?? false) !== true || ($metadata['high_water'] ?? false) !== true) { continue; }
            $options[] = HTMLHelper::_('select.option', $id, $id . ' (' . $metadata['version'] . ')');
            $found = $found || (string) $this->value === $id;
        }
        if (!$found && is_string($this->value) && $this->value !== '') { $options[] = HTMLHelper::_('select.option', $this->value, $this->value . ' — ' . $app->getLanguage()->_('COM_NICODE_FORM_STUDIO_SEARCH_PROVIDER_UNAVAILABLE')); }
        return array_merge(parent::getOptions(), $options);
    }
}
