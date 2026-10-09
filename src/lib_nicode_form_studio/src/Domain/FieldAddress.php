<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Domain;

/** Stable answer identity independent of the position of repeated instances. */
final readonly class FieldAddress
{
    public function __construct(public string $field, public array $instances = [])
    {
        if (!Uuid::valid($field) || !array_is_list($instances) || count($instances) > 64) { throw new \InvalidArgumentException('Invalid field address.'); }
        $groups = [];
        foreach ($instances as $instance) {
            if (!is_array($instance) || count($instance) !== 2 || !Uuid::valid($instance['group'] ?? null) || !Uuid::valid($instance['instance'] ?? null)
                || isset($groups[$instance['group']]) || $instance['group'] === $field) { throw new \InvalidArgumentException('Invalid repeated instance address.'); }
            $groups[$instance['group']] = true;
        }
    }

    public function key(): string
    {
        $segments = [];
        foreach ($this->instances as $instance) { array_push($segments, $instance['group'], $instance['instance']); }
        $segments[] = $this->field;
        return implode('/', $segments);
    }

    public static function fromKey(string $key): self
    {
        // 129 UUIDs separated by 128 slashes: at most 64 repeated ancestors.
        if (strlen($key) > 4772) { throw new \InvalidArgumentException('Field address exceeds supported depth.'); }
        $segments = explode('/', $key);
        if (count($segments) % 2 !== 1) { throw new \InvalidArgumentException('Invalid field address shape.'); }
        $field = array_pop($segments); $instances = [];
        for ($i = 0; $i < count($segments); $i += 2) { $instances[] = ['group' => $segments[$i], 'instance' => $segments[$i + 1]]; }
        return new self($field, $instances);
    }
}
