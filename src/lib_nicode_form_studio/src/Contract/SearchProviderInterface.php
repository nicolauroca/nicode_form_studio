<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Contract;

use Nicode\FormStudio\Domain\FormSpec;
use Nicode\FormStudio\Search\SearchPage;
use Nicode\FormStudio\Search\SearchRequest;
use Nicode\FormStudio\Search\SearchScope;

interface SearchProviderInterface extends ProviderInterface
{
    public function search(SearchRequest $request, SearchScope $scope, ?FormSpec $selectedForm = null): SearchPage;
}
