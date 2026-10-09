<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Search;

final readonly class SearchPage
{
    public function __construct(public array $rows, public ?string $nextCursor, public ?int $total = null) {}
}
