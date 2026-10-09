<?php
declare(strict_types=1);
use Nicode\FormStudio\Contract\SearchProviderInterface;
use Nicode\FormStudio\Domain\{FormSpec, Uuid};
use Nicode\FormStudio\Registry\SearchProviderRegistry;
use Nicode\FormStudio\Search\{CursorCodec, SearchPage, SearchRequest, SearchScope, SelectedSearch};

final class SelectionSearchFixture implements SearchProviderInterface
{
    public array $requests = []; public array $rows; public ?string $next = 'second-page'; public bool $privateHistory = true; public array $sorts = ['received_at_desc'];
    public function __construct(private string $identifier = 'fixture.search', private string $release = '1.0.0') { $this->rows = [['id' => 9, 'uuid' => Uuid::create(), 'form_id' => 1, 'received_at' => '2026-01-01 12:00:00', 'private_payload' => 'must-not-escape']]; }
    public function id(): string { return $this->identifier; }
    public function version(): string { return $this->release; }
    public function metadata(): array { return ['keyset' => true, 'historical_privacy' => $this->privateHistory, 'high_water' => true, 'sorts' => $this->sorts, 'filter_operators' => ['text' => ['equals']]]; }
    public function validateConfiguration(array $configuration, string $path): array { return []; }
    public function search(SearchRequest $request, SearchScope $scope, ?FormSpec $selectedForm = null): SearchPage { $this->requests[] = $request; return new SearchPage($this->rows, $this->next); }
}
function selectedFixture(SelectionSearchFixture $provider): SelectedSearch { $registry = new SearchProviderRegistry(); $registry->register($provider); return new SelectedSearch($registry, $provider->id(), new CursorCodec(str_repeat('selected-search-test-', 3))); }

test('selected search binds cursors to provider version scope query and high-water mark', function (): void {
    $provider = new SelectionSearchFixture(); $selected = selectedFixture($provider); $scope = new SearchScope([1 => false]);
    $page = $selected->search(new SearchRequest(limit: 1), $scope); same(false, isset($page->rows[0]['private_payload']));
    $provider->next = null; $provider->rows[0]['id'] = 8;
    $selected->search(new SearchRequest(limit: 1, cursor: $page->nextCursor), $scope); same('second-page', $provider->requests[1]->cursor);
    $provider->rows[0]['id'] = 9;
    raises(DomainException::class, fn () => $selected->search(new SearchRequest(limit: 1, cursor: $page->nextCursor), $scope));
    $provider->rows[0]['id'] = 8;
    foreach ([new SearchRequest(['state' => 'spam'], limit: 1, cursor: $page->nextCursor), new SearchRequest(limit: 1, cursor: $page->nextCursor, highId: 20)] as $request) { raises(InvalidArgumentException::class, fn () => $selected->search($request, $scope)); }
    raises(InvalidArgumentException::class, fn () => $selected->search(new SearchRequest(cursor: $page->nextCursor), new SearchScope([1 => true])));
    foreach ([new SelectionSearchFixture('fixture.other'), new SelectionSearchFixture('fixture.search', '1.0.1')] as $changed) { raises(InvalidArgumentException::class, fn () => selectedFixture($changed)->search(new SearchRequest(cursor: $page->nextCursor), $scope)); }
    same(['equals'], SelectedSearch::operators($selected, 'text'));
});

test('selected search rejects invalid pages and never calls a provider for an empty ACL scope', function (): void {
    $provider = new SelectionSearchFixture(); $selected = selectedFixture($provider); same([], $selected->search(new SearchRequest(), new SearchScope([]))->rows); same([], $provider->requests);
    $scope = new SearchScope([1 => false]); $provider->rows[0]['form_id'] = 2;
    raises(DomainException::class, fn () => $selected->search(new SearchRequest(), $scope));
    $provider->rows[0]['form_id'] = 1;
    raises(DomainException::class, fn () => $selected->search(new SearchRequest(highId: 8), $scope));
    $provider->rows[] = $provider->rows[0]; raises(DomainException::class, fn () => $selected->search(new SearchRequest(), $scope));
    $provider->rows[1]['id'] = 10; raises(DomainException::class, fn () => $selected->search(new SearchRequest(), $scope));
    $provider->rows = []; raises(DomainException::class, fn () => $selected->search(new SearchRequest(), $scope));
});

test('search selection rejects missing capabilities and jobs cannot switch provider or version', function (): void {
    $provider = new SelectionSearchFixture(); $provider->privateHistory = false;
    raises(DomainException::class, fn () => selectedFixture($provider)); $provider->privateHistory = true;
    SelectedSearch::assertJob($provider, ['search_provider' => ['id' => $provider->id(), 'version' => $provider->version()]]);
    raises(DomainException::class, fn () => SelectedSearch::assertJob($provider, []));
    raises(DomainException::class, fn () => SelectedSearch::assertJob($provider, ['search_provider' => ['id' => $provider->id(), 'version' => '1.0.1']]));
    raises(DomainException::class, fn () => new SelectedSearch(new SearchProviderRegistry(), 'missing', new CursorCodec(str_repeat('k', 32))));
});

test('selected search enforces declared ascending order and propagates it to providers', function (): void {
    $provider = new SelectionSearchFixture(); $selected = selectedFixture($provider); $scope = new SearchScope([1 => false]);
    raises(DomainException::class, fn () => $selected->search(new SearchRequest(sort: 'id_asc'), $scope));
    same([], $provider->requests); $provider->sorts = SearchRequest::SORTS;
    $first = $selected->search(new SearchRequest(sort: 'id_asc'), $scope);
    same('id_asc', $provider->requests[0]->sort);
    $provider->next = null; $provider->rows[0]['id'] = 10; $provider->rows[0]['received_at'] = '2020-01-01 12:00:00';
    $selected->search(new SearchRequest(cursor: $first->nextCursor, sort: 'id_asc'), $scope);
    $provider->rows[0]['id'] = 8;
    raises(DomainException::class, fn () => $selected->search(new SearchRequest(cursor: $first->nextCursor, sort: 'id_asc'), $scope));
    raises(InvalidArgumentException::class, fn () => $selected->search(new SearchRequest(cursor: $first->nextCursor, sort: 'received_at_asc'), $scope));
});

test('selected search rejects instance correlation before invoking an incapable provider', function (): void {
    $provider = new SelectionSearchFixture(); $selected = selectedFixture($provider);
    $request = new SearchRequest(['form_id'=>1], [['field'=>Uuid::create(),'operator'=>'equals','value'=>'needle','same_instance'=>'contact']]);
    raises(DomainException::class, fn () => $selected->search($request, new SearchScope([1=>false])));
    same([], $provider->requests);
});
