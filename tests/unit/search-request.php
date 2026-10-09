<?php
declare(strict_types=1);
use Nicode\FormStudio\Search\SearchRequest;
test('Search request rejects invalid headers and unbounded filter lists', function (): void {
    foreach ([['form_id' => -1], ['user_id' => '1 OR 1=1'], ['received_from' => '2026-02-30 00:00:00'], ['received_to' => '2026-01-01'], ['state' => []], ['state' => str_repeat('a', 256)], ['received_from' => '2026-02-01 00:00:00', 'received_to' => '2026-01-01 00:00:00']] as $filters) { raises(InvalidArgumentException::class, fn () => new SearchRequest($filters)); }
    raises(InvalidArgumentException::class, fn () => new SearchRequest(fieldFilters: array_fill(0, 51, [])));
    raises(InvalidArgumentException::class, fn () => new SearchRequest(fieldFilters: ['untrusted' => []]));
    same(0, (new SearchRequest(['user_id' => 0, 'received_from' => '2024-02-29 23:59:59']))->filters['user_id']);
});
