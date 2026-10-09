<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Infrastructure\Joomla;

use Joomla\Event\AbstractEvent;

/** Read-only lifecycle metadata. Answers, credentials and request tokens are not exposed. */
final class LifecycleEvent extends AbstractEvent
{
    public const PHASES = ['BeforeFormRender', 'AfterFormRender', 'BeforeValidation', 'AfterValidation', 'BeforeSubmissionPersist', 'AfterSubmissionPersist', 'BeforeAction', 'AfterAction', 'ResolveDataSource', 'BeforeExport', 'AfterExport'];
    public function __construct(public readonly string $phase, public readonly array $context)
    {
        if (!in_array($phase, self::PHASES, true)) { throw new \InvalidArgumentException('Unknown lifecycle phase.'); }
        if (array_diff(array_keys($context), ['form_uuid', 'form_id', 'version_id', 'submission_uuid', 'action_uuid', 'action_run_id', 'provider_id', 'provider_version', 'channel', 'locale', 'valid', 'error_count', 'replayed', 'state', 'format', 'mode', 'definition_hash', 'job_id', 'processed', 'complete', 'cache_hit']) !== []) { throw new \InvalidArgumentException('Unsupported lifecycle metadata.'); }
        foreach ($context as $value) { if (!is_scalar($value) && $value !== null) { throw new \InvalidArgumentException('Lifecycle metadata must be scalar.'); } }
        parent::__construct('onFormStudio' . $phase, ['phase' => $phase, 'context' => $context]);
    }
    public function offsetSet(mixed $offset, mixed $value): void { throw new \LogicException('Lifecycle context is immutable.'); }
    public function offsetUnset(mixed $offset): void { throw new \LogicException('Lifecycle context is immutable.'); }
}
