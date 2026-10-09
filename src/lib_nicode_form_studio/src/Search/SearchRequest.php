<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Search;

final readonly class SearchRequest
{
    public const SORTS = ['received_at_desc', 'received_at_asc', 'id_desc', 'id_asc'];
    public function __construct(public array $filters = [], public array $fieldFilters = [], public int $limit = 50, public ?string $cursor = null, public ?int $highId = null, public string $sort = 'received_at_desc')
    {
        if (!in_array($sort, self::SORTS, true)) { throw new \InvalidArgumentException('Unsupported response order.'); }
        if ($highId !== null && $highId < 0) { throw new \InvalidArgumentException('Invalid internal search window.'); }
        if ($limit < 1 || $limit > 500) { throw new \InvalidArgumentException('Page limit must be between 1 and 500.'); }
        if (array_diff(array_keys($filters), ['id', 'uuid', 'form_id', 'state', 'user_id', 'channel', 'action_status', 'received_from', 'received_to']) !== []) {
            throw new \InvalidArgumentException('Unknown submission filter.');
        }
        if (count($fieldFilters) > 50 || !array_is_list($fieldFilters)) { throw new \InvalidArgumentException('Invalid field filters.'); }
        foreach ($filters as $key => $value) {
            if (!is_string($value) && !is_int($value)) { throw new \InvalidArgumentException('Invalid header filter.'); }
            if (strlen((string) $value) > 255) { throw new \InvalidArgumentException('Header filter too long.'); }
            if (in_array($key, ['id', 'form_id', 'user_id'], true) && (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < ($key === 'user_id' ? 0 : 1))) { throw new \InvalidArgumentException('Invalid ID filter.'); }
            if (in_array($key, ['received_from', 'received_to'], true)) {
                $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC')) : false;
                if (!$date || $date->format('Y-m-d H:i:s') !== $value) { throw new \InvalidArgumentException('Invalid UTC date filter.'); }
            }
        }
        if (isset($filters['received_from'], $filters['received_to']) && $filters['received_from'] > $filters['received_to']) { throw new \InvalidArgumentException('Reversed date interval.'); }
    }
    public function ascending(): bool { return str_ends_with($this->sort, '_asc'); }
    public function byId(): bool { return str_starts_with($this->sort, 'id_'); }
}
