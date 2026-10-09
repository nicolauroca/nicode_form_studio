<?php
declare(strict_types=1);
namespace NicodeFixture\Plugin\FormStudios\ProviderFixture\Provider;

use Nicode\FormStudio\Contract\SearchProviderInterface;
use Nicode\FormStudio\Domain\FormSpec;
use Nicode\FormStudio\Search\{SearchRequest, SearchScope, SearchPage};

final readonly class FixtureSearch implements SearchProviderInterface
{
    public function __construct(private SearchProviderInterface $delegate) {}
    public function id(): string { return 'fixture.search'; }
    public function version(): string { return '1.0.0'; }
    public function metadata(): array { return array_replace($this->delegate->metadata(), ['id' => $this->id(), 'version' => $this->version()]); }
    public function validateConfiguration(array $configuration, string $path): array { return $this->delegate->validateConfiguration($configuration, $path); }
    public function search(SearchRequest $request, SearchScope $scope, ?FormSpec $selectedForm = null): SearchPage { return $this->delegate->search($request, $scope, $selectedForm); }
}
