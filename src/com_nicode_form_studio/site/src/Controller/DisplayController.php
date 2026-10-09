<?php
declare(strict_types=1);
namespace Nicode\Component\FormStudio\Site\Controller;
defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Nicode\FormStudio\Application\FormDisplay;
use Nicode\FormStudio\Infrastructure\Joomla\{RequestAdapter, RuntimeAssets, RuntimeMessages};

final class DisplayController extends BaseController
{
    public function display($cachable = false, $urlparams = [])
    {
        $id = $this->input->getInt('id');
        $runtime = null;
        $this->app->allowCache(false); $this->app->setHeader('Cache-Control', 'private, no-store, max-age=0', true);
        try {
            $runtime = $this->app->bootComponent('com_nicode_form_studio')->runtime($this->app);
            $result = $runtime->get(FormDisplay::class)->render($id, $runtime->get(RequestAdapter::class)->context('component'), 'nfs-page-' . bin2hex(random_bytes(6)), Route::_('index.php?option=com_nicode_form_studio&task=form.submit', false), $this->app->getFormToken(), RuntimeMessages::all($this->app->getLanguage()));
        } catch (\OutOfBoundsException) { throw new \RuntimeException($this->app->getLanguage()->_('COM_NICODE_FORM_STUDIO_UNAVAILABLE'), 404); }
        catch (\Throwable) {
            $correlation = \Nicode\FormStudio\Domain\Uuid::create();
            try { $runtime?->get(\Nicode\FormStudio\Infrastructure\Database\TechnicalLog::class)->record('ERROR', 'form.render_failed', $correlation); } catch (\Throwable) {}
            $this->app->setHeader('Status', '503', true);
            $result = ['title' => '', 'html' => '', 'result' => ['message' => $this->app->getLanguage()->_('COM_NICODE_FORM_STUDIO_RENDER_ERROR'), 'reference' => $correlation]];
        }
        if ($result['html'] !== '') { RuntimeAssets::load($this->app); }
        $view = $this->getView('Form', 'html'); $view->form = $result; $view->document = $this->app->getDocument();
        $view->display();
        return $this;
    }
}
