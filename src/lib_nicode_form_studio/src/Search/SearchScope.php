<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Search;

/** Construct only from server-side Joomla ACL decisions, never posted arrays. */
final readonly class SearchScope
{
    /** @param array<int,bool> $forms Form ID => may view sensitive values */
    public function __construct(public array $forms)
    {
        foreach ($forms as $id => $sensitive) {
            if (!is_int($id) || $id < 1 || !is_bool($sensitive)) { throw new \InvalidArgumentException('Invalid authorized search scope.'); }
        }
    }
}
