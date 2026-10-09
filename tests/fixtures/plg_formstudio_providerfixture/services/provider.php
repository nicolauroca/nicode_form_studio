<?php
declare(strict_types=1);
defined('_JEXEC') or die;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\{Container, ServiceProviderInterface};
return new class implements ServiceProviderInterface {
    public function register(Container $container)
    {
        $container->set(PluginInterface::class, static function () {
            $plugin = new \NicodeFixture\Plugin\FormStudios\ProviderFixture\Extension\ProviderFixture((array) PluginHelper::getPlugin('formstudio', 'providerfixture'));
            $plugin->setApplication(\Joomla\CMS\Factory::getApplication());
            return $plugin;
        });
    }
};
