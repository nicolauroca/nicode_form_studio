<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Search;

use Nicode\FormStudio\Contract\SearchProviderInterface;
use Nicode\FormStudio\Domain\{CanonicalJson, FormSpec, Uuid};
use Nicode\FormStudio\Registry\SearchProviderRegistry;

/** The configured provider shares the same scope and signed cursor boundary everywhere. */
final readonly class SelectedSearch implements SearchProviderInterface
{
    private SearchProviderInterface $provider;
    public function __construct(SearchProviderRegistry $providers, string $id, private CursorCodec $cursors)
    {
        $this->provider = $providers->get($id);
        $metadata = $this->provider->metadata();
        foreach (['keyset', 'historical_privacy', 'high_water'] as $capability) { if (($metadata[$capability] ?? false) !== true) { throw new \DomainException('Configured search provider lacks a required capability.'); } }
        if (!in_array('received_at_desc', $metadata['sorts'] ?? [], true) || $this->provider->validateConfiguration([], '/search') !== []) { throw new \DomainException('Configured search provider is not ready.'); }
    }
    public function id(): string { return $this->provider->id(); }
    public function version(): string { return $this->provider->version(); }
    public function metadata(): array { return $this->provider->metadata(); }
    public function validateConfiguration(array $configuration, string $path): array { return $this->provider->validateConfiguration($configuration, $path); }
    public static function operators(SearchProviderInterface $provider, string $indexType): array
    {
        $operators = $provider->metadata()['filter_operators'][$indexType] ?? [];
        if (!is_array($operators) || !array_is_list($operators) || count(array_filter($operators, is_string(...))) !== count($operators) || array_diff($operators, ['equals', 'not_equals', 'contains', 'starts_with', 'greater', 'less', 'greater_equal', 'less_equal', 'between', 'before', 'after']) !== []) { throw new \DomainException('Invalid search operator capabilities.'); }
        return $operators;
    }
    public static function assertJob(SearchProviderInterface $provider, array $parameters): void
    {
        $pin = $parameters['search_provider'] ?? ['id' => 'sql', 'version' => '1.0.0'];
        if (!is_array($pin) || ($pin['id'] ?? null) !== $provider->id() || ($pin['version'] ?? null) !== $provider->version()) { throw new \DomainException('Job search provider changed; restore the pinned provider before resuming.'); }
    }
    public function search(SearchRequest $request, SearchScope $scope, ?FormSpec $selectedForm = null): SearchPage
    {
        if (!in_array($request->sort, $this->metadata()['sorts'] ?? [], true)) { throw new \DomainException('Search provider does not support this order.'); }
        foreach ($request->fieldFilters as $filter) {
            if (is_array($filter) && array_key_exists('same_instance', $filter) && ($this->metadata()['same_instance'] ?? false) !== true) { throw new \DomainException('Search provider does not support instance correlation.'); }
        }
        if ($scope->forms === []) { return new SearchPage([], null); }
        $identity = [$this->id(), $this->version(), $request->filters, $request->fieldFilters, $request->highId, $scope->forms, $selectedForm?->hash];
        if ($request->sort !== 'received_at_desc') { $identity[] = $request->sort; }
        $fingerprint = hash('sha256', CanonicalJson::encode($identity));
        $cursor = null; $boundary = null;
        if ($request->cursor !== null) {
            $decoded = $this->cursors->decode($request->cursor);
            if ($this->id() === 'sql' && $this->version() === '1.0.0' && isset($decoded['query'], $decoded['received_at'], $decoded['id']) && array_diff(array_keys($decoded), ['query', 'received_at', 'id']) === []) {
                // Existing SQL cursors retain their provider's own scope/query verification.
                $cursor = $request->cursor;
                $boundary = [$request->byId() ? '' : $decoded['received_at'], (int) $decoded['id']];
            } else {
                if (($decoded['query'] ?? null) !== $fingerprint || !is_string($decoded['cursor'] ?? null) || !is_array($decoded['last'] ?? null) || !array_is_list($decoded['last']) || count($decoded['last']) !== 2 || !is_string($decoded['last'][0]) || !is_int($decoded['last'][1])) { throw new \InvalidArgumentException('Search provider, scope or query changed.'); }
                $cursor = $decoded['cursor']; $boundary = $decoded['last'];
            }
        }
        $page = $this->provider->search(new SearchRequest($request->filters, $request->fieldFilters, $request->limit, $cursor, $request->highId, $request->sort), $scope, $selectedForm);
        if (!array_is_list($page->rows) || count($page->rows) > $request->limit || ($page->nextCursor !== null && (strlen($page->nextCursor) > 2000 || $page->nextCursor === $cursor || $page->rows === []))) { throw new \DomainException('Search provider returned an invalid page.'); }
        $rows = []; $seen = []; $previous = $boundary;
        foreach ($page->rows as $row) {
            if (!is_array($row)) { throw new \DomainException('Search provider returned an invalid row.'); }
            $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT); $form = filter_var($row['form_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1 || !$form || !array_key_exists($form, $scope->forms) || isset($seen[$id]) || !Uuid::valid($row['uuid'] ?? null) || !is_string($row['received_at'] ?? null) || ($request->highId !== null && $id > $request->highId) || (isset($request->filters['form_id']) && $form !== (int) $request->filters['form_id'])) { throw new \DomainException('Search provider returned an out-of-scope row.'); }
            $order = [$request->byId() ? '' : $row['received_at'], $id];
            if ($previous !== null && ($request->ascending() ? $order <= $previous : $order >= $previous)) { throw new \DomainException('Search provider returned unstable ordering.'); }
            $previous = $order; $seen[$id] = true;
            $rows[] = array_intersect_key($row, array_flip(['id', 'uuid', 'form_id', 'form_version_id', 'state', 'received_at', 'channel', 'action_status']));
        }
        return new SearchPage($rows, $page->nextCursor === null ? null : $this->cursors->encode(['query' => $fingerprint, 'cursor' => $page->nextCursor, 'last' => $previous]));
    }
}
