<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Domain;

/** Stable identities distinguish moved definitions from unrelated replacements. */
final class SpecDiff
{
    public function compare(array $before, array $after, int $limit = 1000): array
    {
        if ($limit < 1 || $limit > 5000) { throw new \InvalidArgumentException('Invalid comparison limit.'); }
        $changes = []; $truncated = false;
        $add = static function (string $kind, string $path, mixed $left, mixed $right) use (&$changes, &$truncated, $limit): void {
            if (count($changes) >= $limit) { $truncated = true; return; }
            $changes[] = ['kind' => $kind, 'path' => $path, 'before' => $left, 'after' => $right];
        };
        $walk = function (mixed $left, mixed $right, string $path, int $depth = 0) use (&$walk, $add, &$truncated): void {
            if ($truncated || $left === $right) { return; }
            if ($depth > 64) { throw new \InvalidArgumentException('Comparison nesting exceeds limit.'); }
            if (!is_array($left) || !is_array($right)) { $add('changed', $path, $left, $right); return; }
            $leftIds = $this->identities($left); $rightIds = $this->identities($right);
            if ($leftIds !== null && $rightIds !== null && ($left !== [] || $right !== [])) {
                $sharedLeft = array_values(array_intersect($leftIds, $rightIds)); $sharedRight = array_values(array_intersect($rightIds, $leftIds));
                if ($sharedLeft !== $sharedRight) { $add('reordered', $path . '/@order', $leftIds, $rightIds); }
                $left = array_column($left, null, 'uuid'); $right = array_column($right, null, 'uuid');
            }
            $keys = array_values(array_unique([...array_keys($left), ...array_keys($right)], SORT_REGULAR));
            foreach ($keys as $key) {
                $next = $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $key);
                if (!array_key_exists($key, $left)) { $add('added', $next, null, $right[$key]); }
                elseif (!array_key_exists($key, $right)) { $add('removed', $next, $left[$key], null); }
                else { $walk($left[$key], $right[$key], $next, $depth + 1); }
                if ($truncated) { break; }
            }
        };
        $walk($before, $after, '');
        return ['changes' => $changes, 'truncated' => $truncated];
    }

    private function identities(array $items): ?array
    {
        if (!array_is_list($items)) { return null; }
        $ids = [];
        foreach ($items as $item) {
            if (!is_array($item) || !Uuid::valid($item['uuid'] ?? null)) { return null; }
            $ids[] = $item['uuid'];
        }
        return count(array_unique($ids)) === count($ids) ? $ids : null;
    }
}
