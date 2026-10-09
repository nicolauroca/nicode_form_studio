<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Infrastructure\Joomla;

use Joomla\Event\AbstractEvent;
use Nicode\FormStudio\Registry\ProviderRegistry;
use Nicode\FormStudio\Rendering\FieldRendererRegistry;

/** Plugins register on the supplied registry; they cannot replace event context. */
final class ProviderRegistrationEvent extends AbstractEvent
{
    public function __construct(public readonly string $kind, public readonly ProviderRegistry|FieldRendererRegistry $registry)
    {
        if (!in_array($kind, ['fields', 'validators', 'operators', 'effects', 'sources', 'actions', 'storage', 'renderers', 'jobs', 'search'], true)) { throw new \InvalidArgumentException('Unknown registry kind.'); }
        parent::__construct('onFormStudioRegisterProviders', ['kind' => $kind, 'registry' => $registry]);
    }
    public function offsetSet(mixed $offset, mixed $value): void { throw new \LogicException('Registration event context is immutable.'); }
    public function offsetUnset(mixed $offset): void { throw new \LogicException('Registration event context is immutable.'); }
}
