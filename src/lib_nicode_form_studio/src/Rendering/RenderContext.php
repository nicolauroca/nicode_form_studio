<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Rendering;

final readonly class RenderContext
{
    public function __construct(public string $instance, public int $formId, public int $versionId, public string $action, public string $csrfName, public string $attempt, public array $messages = [], public bool $preview = false, public string $channel = 'component', public bool $previewRows = false)
    {
        if (!in_array($channel, ['component', 'module'], true)) { throw new \InvalidArgumentException('Invalid render channel.'); }
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $instance) !== 1 || preg_match('/^[a-zA-Z0-9_-]+$/D', $csrfName) !== 1) { throw new \InvalidArgumentException('Invalid render identifiers.'); }
        if ((!str_starts_with($action, 'index.php?') && !str_starts_with($action, '/')) || str_starts_with($action, '//') || strpbrk($action, "\r\n\\") !== false) { throw new \InvalidArgumentException('Submit action must be a trusted internal route.'); }
    }
    public function message(string $key, string $fallback): string { return $this->messages[$key] ?? $fallback; }
}
