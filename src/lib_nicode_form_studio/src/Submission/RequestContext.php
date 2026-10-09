<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Submission;
/** Constructed by the Joomla adapter, never by mass assignment of request data. */
final readonly class RequestContext
{
    public function __construct(public int $userId, public array $viewLevels, public string $language, public string $sessionBinding, public string $rateScope, public bool $csrfValid, public string $channel = 'component', public array $trustedValues = [], public array $userProperties = [], public array $prefillQuery = [], public array $prefillContext = [], private ?\Closure $metadataProvider = null)
    {
        if ($userId < 0 || $sessionBinding === '' || preg_match('/^[a-f0-9]{64}$/D', $rateScope) !== 1 || !in_array($channel, ['component', 'module'], true)) { throw new \InvalidArgumentException('Invalid trusted submission context.'); }
        foreach ($viewLevels as $level) { if (!is_int($level) || $level < 1) { throw new \InvalidArgumentException('Invalid access level.'); } }
    }
    public function ruleContext(): array { return ['user_id' => $this->userId, 'language' => $this->language, 'view_levels' => $this->viewLevels, 'user_properties' => $this->userProperties, 'prefill_query' => $this->prefillQuery, 'prefill_context' => $this->prefillContext]; }
    public function requestMetadata(array $privacy, string $mode): array
    {
        if ($mode === 'none' || (($privacy['store_ip'] ?? false) !== true && ($privacy['store_user_agent'] ?? false) !== true) || $this->metadataProvider === null) { return []; }
        return \Nicode\FormStudio\Privacy\RequestMetadata::select($privacy, $mode, ($this->metadataProvider)($privacy));
    }
}
