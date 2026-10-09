<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Actions;

use Nicode\FormStudio\Infrastructure\Database\ActionRunRepository;
use Nicode\FormStudio\Registry\ActionRegistry;
use Nicode\FormStudio\Registry\FieldTypeRegistry;
use Nicode\FormStudio\Rules\ConditionEvaluator;

final readonly class ActionEngine
{
    public function __construct(private ActionRegistry $actions, private ActionRunRepository $runs, private ConditionEvaluator $conditions, private FieldTypeRegistry $fields, private ?\Nicode\FormStudio\Infrastructure\Database\TechnicalLog $log = null, private ?\Nicode\FormStudio\Registry\ProviderDependencies $dependencies = null, private ?\Nicode\FormStudio\Contract\LifecycleEventsInterface $events = null) {}
    public function execute(int $submission, ActionContext $context, bool $retryFailed = false, ?array $retryAttempts = null): array
    {
        $this->runs->assertContext($submission, $context);
        $this->dependencies?->assert($context->spec);
        $spec = $context->definition(); $datatypes = []; $results = []; $navigation = []; $summary = 'succeeded';
        usort($spec['actions'], static fn (array $a, array $b): int => [($a['order'] ?? 0), $a['uuid']] <=> [($b['order'] ?? 0), $b['uuid']]);
        foreach ($spec['fields'] as $field) { $datatypes[$field['uuid']] = $this->fields->get($field['type'])->metadata()['datatype']; }
        foreach ($spec['actions'] as $action) {
            if (!($action['enabled'] ?? true)) { continue; }
            $compatible = $this->actions->has($action['type']) && ($this->actions->get($action['type'])->metadata()['retry'] ?? null) === 'definite_failure_only';
            $retry = $retryFailed && $compatible && ($retryAttempts === null || isset($retryAttempts[$action['uuid']]));
            $lease = $this->runs->claim($submission, $action['uuid'], $action['type'], $retry, $retry ? ($retryAttempts[$action['uuid']] ?? null) : null);
            if ($lease === null) {
                $state = $this->runs->latest($submission, $action['uuid'])['state'] ?? 'pending';
                if ($state === 'succeeded' && $this->actions->has($action['type']) && ($this->actions->get($action['type'])->metadata()['side_effect'] ?? true) === false) {
                    $navigation = array_replace($navigation, $this->actions->get($action['type'])->execute($action['config'] ?? [], $context->forAction($action['uuid']))->navigation);
                }
            }
            else {
                $nextNavigation = [];
                try {
                    if (isset($action['condition']) && !$context->matchesCondition($action['condition'], $this->conditions, $datatypes)) {
                        $state = 'skipped'; $code = 'condition_false';
                    } elseif (!$this->actions->has($action['type'])) {
                        $state = 'failed'; $code = 'provider_missing';
                    } else {
                        $provider = $this->actions->get($action['type']);
                        if ($provider->validateConfiguration($action['config'] ?? [], '/action') !== []) { throw new ActionFailure('configuration_invalid'); }
                        try { $this->events?->emit('BeforeAction', ['form_uuid' => $spec['uuid'], 'submission_uuid' => $context->reference, 'action_uuid' => $action['uuid'], 'action_run_id' => $lease->id, 'provider_id' => $provider->id(), 'provider_version' => $provider->version()]); }
                        catch (\Throwable) { throw new ActionFailure('extension_rejected'); }
                        $outcome = $provider->execute($action['config'] ?? [], $context->forAction($action['uuid']));
                        $state = 'succeeded'; $code = $outcome->code; $nextNavigation = $outcome->navigation;
                    }
                } catch (ActionFailure $error) {
                    $state = $error->unknownOutcome ? 'unknown' : 'failed'; $code = $error->resultCode;
                } catch (\Throwable) {
                    $state = 'unknown'; $code = 'unexpected_action_outcome';
                }
                // A persistence failure after a side effect must propagate, never be
                // mistaken for a provider failure and followed by another finish.
                $this->runs->finish($lease, $state, $code);
                $this->events?->emit('AfterAction', ['form_uuid' => $spec['uuid'], 'submission_uuid' => $context->reference, 'action_uuid' => $action['uuid'], 'action_run_id' => $lease->id, 'provider_id' => $action['type'], 'state' => $state]);
                if (in_array($state, ['failed', 'unknown'], true)) { $this->log?->record('ERROR', 'action.' . $state, $context->reference, ['submission_uuid' => $context->reference, 'action_run_id' => $lease->id]); }
                $navigation = array_replace($navigation, $nextNavigation);
            }
            $results[$action['uuid']] = $state;
            if (in_array($state, ['running', 'pending'], true)) { $summary = 'pending'; break; }
            if (!in_array($state, ['succeeded', 'skipped'], true)) {
                if (($action['failure_policy'] ?? 'non_blocking') === 'blocking') { $summary = 'blocking_failure'; break; }
                $summary = $state === 'running' ? 'pending' : 'partial_failure';
            }
        }
        $this->runs->summarize($submission, $summary);
        return ['status' => $summary, 'actions' => $results, 'navigation' => $navigation];
    }
}
