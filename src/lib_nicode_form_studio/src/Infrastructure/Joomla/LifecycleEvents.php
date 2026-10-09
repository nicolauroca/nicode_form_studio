<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Infrastructure\Joomla;

use Joomla\Event\DispatcherInterface;
use Nicode\FormStudio\Contract\LifecycleEventsInterface;

final readonly class LifecycleEvents implements LifecycleEventsInterface
{
    public function __construct(private DispatcherInterface $dispatcher, private \Closure $loadPlugins, private \Closure $logFailure) {}
    public function emit(string $phase, array $context): void
    {
        $event = new LifecycleEvent($phase, $context);
        try {
            ($this->loadPlugins)($this->dispatcher);
            $this->dispatcher->dispatch($event->getName(), $event);
        } catch (\Throwable $error) {
            try { ($this->logFailure)(); } catch (\Throwable) { /* Logging cannot replace the original outcome. */ }
            if (!str_starts_with($phase, 'After')) { throw new \DomainException('Lifecycle extension rejected the operation.', previous: $error); }
        }
    }
}
