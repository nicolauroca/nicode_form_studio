<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Infrastructure\Joomla;

use Joomla\Event\DispatcherInterface;
use Nicode\FormStudio\Registry\ProviderRegistry;
use Nicode\FormStudio\Rendering\FieldRendererRegistry;

final readonly class ProviderDiscovery
{
    public function __construct(private DispatcherInterface $dispatcher, private \Closure $loadPlugins) {}

    /** @template T of ProviderRegistry|FieldRendererRegistry @param T $registry @return T */
    public function complete(string $kind, ProviderRegistry|FieldRendererRegistry $registry): ProviderRegistry|FieldRendererRegistry
    {
        ($this->loadPlugins)($this->dispatcher);
        try { $this->dispatcher->dispatch('onFormStudioRegisterProviders', new ProviderRegistrationEvent($kind, $registry)); }
        finally { $registry->freeze(); }
        return $registry;
    }
}
