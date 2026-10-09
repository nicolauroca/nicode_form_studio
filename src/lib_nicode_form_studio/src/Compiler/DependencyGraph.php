<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Compiler;

final class DependencyGraph
{
    /** @var array<string, array<string, bool>> */
    private array $edges = [];

    public function add(string $source, string $target): void
    {
        $this->edges[$source][$target] = true;
    }

    /** @return list<string> A cycle path, or an empty list. */
    public function cycle(): array
    {
        $done = [];
        foreach (array_keys($this->edges) as $root) {
            $stack = [[$root, false]];
            $active = [];
            while ($stack !== []) {
                [$node, $leaving] = array_pop($stack);
                if ($leaving) {
                    unset($active[$node]);
                    $done[$node] = true;
                    continue;
                }
                if (isset($active[$node])) {
                    return [...array_keys($active), $node];
                }
                if (isset($done[$node])) {
                    continue;
                }
                $active[$node] = true;
                $stack[] = [$node, true];
                foreach (array_keys($this->edges[$node] ?? []) as $next) {
                    $stack[] = [$next, false];
                }
            }
        }
        return [];
    }
}
