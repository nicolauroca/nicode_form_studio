<?php
declare(strict_types=1);
defined('_JEXEC') or die;

return new class implements \Joomla\DI\ServiceProviderInterface {
    public function register(\Joomla\DI\Container $container)
    {
        $container->registerServiceProvider(new \Joomla\CMS\Extension\Service\Provider\ModuleDispatcherFactory('\\Nicode\\Module\\FormStudio'));
        $container->registerServiceProvider(new \Joomla\CMS\Extension\Service\Provider\Module());
    }
};
