<?php
declare(strict_types=1);
defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\{ComponentDispatcherFactory, MVCFactory};
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\{Container, ServiceProviderInterface};
use Nicode\Component\FormStudio\Administrator\Extension\FormStudioComponent;

require_once JPATH_LIBRARIES . '/nicode_form_studio/autoload.php';
return new class implements ServiceProviderInterface {
    public function register(Container $container)
    {
        $container->registerServiceProvider(new MVCFactory('\\Nicode\\Component\\FormStudio'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\Nicode\\Component\\FormStudio'));
        $container->set(ComponentInterface::class, static function (Container $c): FormStudioComponent {
            $component = new FormStudioComponent($c->get(ComponentDispatcherFactoryInterface::class));
            $component->setMVCFactory($c->get(MVCFactoryInterface::class));
            $component->setServices($c);
            return $component;
        });
    }
};
