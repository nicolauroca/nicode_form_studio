<?php
declare(strict_types=1);
defined('_JEXEC') or die;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\{Container, ServiceProviderInterface};
use Nicode\Plugin\Extension\FormStudio\Extension\FormStudio;
return new class implements ServiceProviderInterface {
    public function register(Container $container)
    {
        $container->set(PluginInterface::class, static function (): FormStudio {
            $plugin = new FormStudio((array) PluginHelper::getPlugin('extension', 'nicode_form_studio'));
            $plugin->setApplication(Factory::getApplication());
            return $plugin;
        });
    }
};
