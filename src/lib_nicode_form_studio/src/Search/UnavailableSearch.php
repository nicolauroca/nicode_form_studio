<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Search;

use Nicode\FormStudio\Contract\SearchProviderInterface;
use Nicode\FormStudio\Domain\{Diagnostic, FormSpec};

/** An unavailable search engine must not prevent unrelated retention/cleanup jobs. */
final readonly class UnavailableSearch implements SearchProviderInterface
{
    public function __construct(private string $identifier) {}
    public function id(): string { return $this->identifier; }
    public function version(): string { return '0.0.0'; }
    public function metadata(): array { return ['id' => $this->id(), 'version' => $this->version(), 'available' => false, 'filter_operators' => []]; }
    public function validateConfiguration(array $configuration, string $path): array { return [new Diagnostic('search.unavailable', $path, 'The configured search provider is unavailable.')]; }
    public function search(SearchRequest $request, SearchScope $scope, ?FormSpec $selectedForm = null): SearchPage { throw new \DomainException('The configured search provider is unavailable.'); }
}
