<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Actions;

use Nicode\FormStudio\Contract\ActionInterface;
use Nicode\FormStudio\Domain\Diagnostic;
use Nicode\FormStudio\Security\RedirectPolicy;

final readonly class NavigationAction implements ActionInterface
{
    public function __construct(private RedirectPolicy $policy, private \Closure $menuRoute) {}
    public function id(): string { return 'redirect'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return ['id' => $this->id(), 'version' => $this->version(), 'failure_policies' => ['blocking', 'non_blocking'], 'retry' => 'definite_failure_only', 'side_effect' => false, 'configuration_schema' => ['type' => 'object', 'properties' => ['menu_id' => ['type' => 'integer', 'minimum' => 1], 'url' => ['type' => 'string']]]]; }
    public function validateConfiguration(array $configuration, string $path): array
    {
        try { $this->destination($configuration); }
        catch (\Throwable) { return [new Diagnostic('action.navigation', $path, 'Select an available menu item, internal route or approved HTTPS URL.')]; }
        return [];
    }
    public function execute(array $configuration, ActionContext $context): ActionOutcome
    {
        try { $destination = $this->destination($configuration); }
        catch (\Throwable) { throw new ActionFailure('navigation_unavailable'); }
        return new ActionOutcome('navigation_selected', ['redirect' => $destination]);
    }
    private function destination(array $configuration): string
    {
        if (isset($configuration['menu_id'])) {
            if (!is_int($configuration['menu_id']) || $configuration['menu_id'] < 1 || isset($configuration['url'])) { throw new \InvalidArgumentException('Invalid menu route.'); }
            return $this->policy->validate(($this->menuRoute)($configuration['menu_id']));
        }
        if (!is_string($configuration['url'] ?? null)) { throw new \InvalidArgumentException('Missing route.'); }
        return $this->policy->validate($configuration['url']);
    }
}
