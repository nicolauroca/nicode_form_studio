<?php
declare(strict_types=1);
namespace Nicode\Component\FormStudio\Administrator\Extension;
defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Extension\MVCComponent;
use Joomla\DI\Container;
use Nicode\FormStudio\Infrastructure\Joomla\RuntimeProvider;

final class FormStudioComponent extends MVCComponent
{
    private Container $services;
    private ?\WeakMap $runtimes = null;
    public function setServices(Container $container): void { $this->services = $container; }
    public function runtime(CMSApplicationInterface $app): Container
    {
        $this->runtimes ??= new \WeakMap();
        if (!isset($this->runtimes[$app])) {
            $container = new Container($this->services);
            $container->registerServiceProvider(new RuntimeProvider($app, ComponentHelper::getParams('com_nicode_form_studio'), JPATH_ROOT));
            $this->runtimes[$app] = $container;
        }
        return $this->runtimes[$app];
    }
}
