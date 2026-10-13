<?php

declare(strict_types=1);

namespace Padosoft\ProductImageDiscovery\Tests\Support;

use Padosoft\LaravelAiSearchProviders\CallableSearchProviderFactory;
use Padosoft\LaravelAiSearchProviders\Contracts\SearchProviderInterface;
use Padosoft\LaravelAiSearchProviders\Data\SearchProviderDefinition;
use Padosoft\LaravelAiSearchProviders\Data\SearchQueryData;
use Padosoft\LaravelAiSearchProviders\Data\SearchResultCollection;
use Padosoft\LaravelAiSearchProviders\SearchProviderManager;

/**
 * Image provider answering each query with the results of the first script entry whose needle
 * the query contains, and recording the queries it receives.
 */
final class ScriptedSearchProvider implements SearchProviderInterface
{
    /**
     * @var list<string>
     */
    public array $queries = [];

    /**
     * @param array<string, list<array<string, mixed>>> $resultsByNeedle
     */
    public function __construct(private readonly array $resultsByNeedle)
    {
    }

    public function manager(): SearchProviderManager
    {
        return new SearchProviderManager(
            repository: new InMemorySearchProviderConfigRepository([
                SearchProviderDefinition::fromArray([
                    'code' => 'scripted',
                    'name' => 'Scripted',
                    'driver' => 'scripted',
                    'priority' => 10,
                ]),
            ]),
            factories: [
                'scripted' => new CallableSearchProviderFactory(fn (): SearchProviderInterface => $this),
            ],
        );
    }

    public function searchImages(SearchQueryData $query): SearchResultCollection
    {
        $this->queries[] = (string) $query->query;

        foreach ($this->resultsByNeedle as $needle => $results) {
            if (str_contains((string) $query->query, $needle)) {
                return new SearchResultCollection($results);
            }
        }

        return new SearchResultCollection([]);
    }

    public function searchWeb(SearchQueryData $query): SearchResultCollection
    {
        return new SearchResultCollection([]);
    }

    public function supportsImageSearch(): bool
    {
        return true;
    }

    public function supportsSiteFilter(): bool
    {
        return true;
    }
}
