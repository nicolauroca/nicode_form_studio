<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Rendering;

use Nicode\FormStudio\Contract\FieldRendererInterface;

final class FieldRendererRegistry
{
    private array $renderers = [];
    private bool $frozen = false;
    public function register(string $id, FieldRendererInterface $renderer): void
    {
        if ($this->frozen) { throw new \LogicException('Renderer registry is frozen.'); }
        if (preg_match('/^[a-z][a-z0-9_.-]*$/D', $id) !== 1) { throw new \InvalidArgumentException('Invalid renderer identifier.'); }
        if (isset($this->renderers[$id])) { throw new \LogicException('Duplicate field renderer.'); }
        $this->renderers[$id] = $renderer;
    }
    public function get(string $id): FieldRendererInterface { return $this->renderers[$id] ?? throw new \DomainException('Required field renderer unavailable.'); }
    public function has(string $id): bool { return isset($this->renderers[$id]); }
    public function freeze(): void { $this->frozen = true; }
}
