<?php
declare(strict_types=1);
namespace Nicode\Component\FormStudio\Administrator\Field;
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Nicode\FormStudio\Application\FormListing;

/** Shared native menu/module selector; options use the administrative ACL scope. */
final class FormStudioField extends ListField
{
    protected $type = 'FormStudio';
    protected function getOptions()
    {
        $app = Factory::getApplication(); $language = $app->getLanguage(); $language->load('com_nicode_form_studio', JPATH_ADMINISTRATOR);
        $options = [HTMLHelper::_('select.option', '', $language->_('COM_NICODE_FORM_STUDIO_SELECT_FORM'))]; $found = false;
        try {
            $listing = $app->bootComponent('com_nicode_form_studio')->runtime($app)->get(FormListing::class);
            $cursor = null;
            do {
                $page = $listing->page((int) ($app->getIdentity()?->id ?? 0), ['state' => 'published'], $cursor, 100);
                foreach ($page['rows'] as $row) {
                    $options[] = HTMLHelper::_('select.option', (int) $row['id'], $row['name'] . ' (#' . $row['id'] . ')');
                    if ((string) $row['id'] === (string) $this->value) { $found = true; }
                }
                $cursor = $page['next_cursor'];
            } while ($cursor !== null);
        } catch (\DomainException) { /* No titles are disclosed outside the selector's ACL scope. */ }
        if (!$found && filter_var($this->value, FILTER_VALIDATE_INT) !== false && (int) $this->value > 0) {
            $options[] = HTMLHelper::_('select.option', (int) $this->value, sprintf($language->_('COM_NICODE_FORM_STUDIO_SELECTED_UNAVAILABLE'), (int) $this->value));
        }
        return array_merge(parent::getOptions(), $options);
    }
}
