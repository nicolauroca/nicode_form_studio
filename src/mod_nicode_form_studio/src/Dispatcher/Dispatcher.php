<?php
declare(strict_types=1);
namespace Nicode\Module\FormStudio\Site\Dispatcher;
defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Router\Route;
use Nicode\FormStudio\Application\FormDisplay;
use Nicode\FormStudio\Infrastructure\Joomla\{RequestAdapter, RuntimeAssets, RuntimeMessages};

final class Dispatcher extends AbstractModuleDispatcher
{
    protected function getLayoutData()
    {
        $data = parent::getLayoutData();
        $this->app->getLanguage()->load('com_nicode_form_studio', JPATH_SITE . '/components/com_nicode_form_studio');
        $this->app->getLanguage()->load('com_nicode_form_studio', JPATH_SITE, null, false, false);
        $runtime = null;
        $this->app->allowCache(false); $this->app->setHeader('Cache-Control', 'private, no-store, max-age=0', true);
        try {
            $runtime = $this->app->bootComponent('com_nicode_form_studio')->runtime($this->app);
            $data['form'] = $runtime->get(FormDisplay::class)->render((int) $data['params']->get('form_id'), $runtime->get(RequestAdapter::class)->context('module'), 'nfs-module-' . (int) $data['module']->id . '-' . bin2hex(random_bytes(6)), Route::_('index.php?option=com_nicode_form_studio&task=form.submit', false), $this->app->getFormToken(), RuntimeMessages::all($this->app->getLanguage()));
            RuntimeAssets::load($this->app);
        } catch (\OutOfBoundsException) {
            $data['form'] = $data['params']->get('unavailable_mode', 'hide') === 'message'
                ? ['html' => '<p role="status">' . htmlspecialchars($this->app->getLanguage()->_('COM_NICODE_FORM_STUDIO_FORM_UNAVAILABLE'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'] : null;
        }
        catch (\Throwable) {
            $correlation = \Nicode\FormStudio\Domain\Uuid::create();
            try { $runtime?->get(\Nicode\FormStudio\Infrastructure\Database\TechnicalLog::class)->record('ERROR', 'form.render_failed', $correlation); } catch (\Throwable) {}
            $data['form'] = ['html' => '<p role="status">' . htmlspecialchars($this->app->getLanguage()->_('COM_NICODE_FORM_STUDIO_RENDER_ERROR') . ' ' . $correlation, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'];
        }
        return $data;
    }
}
