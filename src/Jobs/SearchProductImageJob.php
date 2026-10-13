<?php

declare(strict_types=1);

namespace Padosoft\ProductImageDiscovery\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Padosoft\ProductImageDiscovery\Actions\GenerateSearchQueriesAction;
use Padosoft\ProductImageDiscovery\DTO\ProductIdentityData;
use Padosoft\ProductImageDiscovery\DTO\SearchQueryData;
use Padosoft\ProductImageDiscovery\Enums\ProductImageDiscoveryRequestStatus;
use Padosoft\ProductImageDiscovery\Jobs\Concerns\DispatchesPipelineJobs;
use Padosoft\ProductImageDiscovery\Jobs\Concerns\HandlesRejectedRun;
use Padosoft\ProductImageDiscovery\Jobs\Concerns\ResolvesQueueName;
use Padosoft\ProductImageDiscovery\Jobs\Contracts\PipelineStoreInterface;
use Padosoft\LaravelAiSearchProviders\Data\SearchProviderExecutionResult;
use Padosoft\LaravelAiSearchProviders\Data\SearchQueryData as ProviderSearchQueryData;
use Padosoft\LaravelAiSearchProviders\Data\SearchResult;
use Padosoft\LaravelAiSearchProviders\Data\SearchResultCollection;
use Padosoft\LaravelAiSearchProviders\SearchProviderManager;
use Padosoft\ProductImageDiscovery\Services\Logging\ProductImageEventLogger;
use Padosoft\ProductImageDiscovery\Services\Support\SearchEscalation;
use Padosoft\ProductImageDiscovery\Services\Support\TextNormalizer;

final class SearchProductImageJob implements ShouldQueue
{
    use Dispatchable;
    use DispatchesPipelineJobs;
    use HandlesRejectedRun;
    use InteractsWithQueue;
    use Queueable;
    use ResolvesQueueName;
    use SerializesModels;

    /**
     * Run continued by an escalation step (see SearchEscalation); null starts a new run. A plain
     * property with a default, so jobs queued before it existed still unserialize.
     */
    private ?int $continuesRun = null;

    public function __construct(private readonly int|string $requestId, ?int $continuesRun = null)
    {
        $this->continuesRun = $continuesRun;
        $this->onQueue($this->queueNameFor('search'));
    }

    public function handle(
        PipelineStoreInterface $store,
        SearchProviderManager $manager,
        ProductImageEventLogger $logger,
        ?GenerateSearchQueriesAction $queryGenerator = null,
    ): array {
        $request = $store->getRequest($this->requestId);

        if ($request === null) {
            return [];
        }

        $previous = $request['context']['search'] ?? [];
        $previous = is_array($previous) ? $previous : [];

        if ($this->continuesRun !== null) {
            // A retry, a re-POST or a reviewer acted after the escalation was queued: it is stale.
            if ((int) ($previous['run'] ?? 0) !== $this->continuesRun
                || ($previous['completed_at'] ?? null) !== null
                || $this->enumValue($request['status'] ?? null) !== ProductImageDiscoveryRequestStatus::Searching->value) {
                return $request;
            }
        } elseif (($previous['completed_at'] ?? null) !== null) {
            return $request;
        }

        $identity = ProductIdentityData::fromArray(array_merge($request, [
            'raw_payload' => $request['raw_payload'] ?? $request,
        ]));

        if ($this->continuesRun !== null) {
            $searchRun = $this->continuesRun;
            $step = max(1, (int) ($previous['step'] ?? 1)) + 1;
            $searchQueries = array_map(
                static fn (array $query): SearchQueryData => SearchQueryData::fromArray($query),
                array_values(array_filter($previous['queries'] ?? [], 'is_array')),
            );
            $executions = array_values(array_filter($previous['executions'] ?? [], 'is_array'));
            // Images of earlier steps were already verified and rejected in this run.
            $seenFingerprints = array_fill_keys(array_filter(array_map(
                static fn (array $candidate): string => (string) ($candidate['fingerprint'] ?? ''),
                $this->candidatesOfCurrentRun($store, $request),
            )), true);
        } else {
            $trustedSources = $store->listTrustedSources($request['client_id'] ?? null);
            $searchQueries = ($queryGenerator ?? new GenerateSearchQueriesAction())->handle($identity, $trustedSources);

            if ($searchQueries === []) {
                $searchQueries = [
                    new SearchQueryData(
                        query: trim(implode(' ', array_filter([$identity->brand, $identity->modelCode, $identity->colorName, $identity->ean, $identity->supplierSku]))),
                        intent: 'fallback',
                        priority: 1,
                    ),
                ];
            }

            // Every search starts a new run: candidates are tagged with it so earlier runs never leak
            // into this run's decision.
            $searchRun = (int) ($request['attempts'] ?? 0) + 1;
            $step = 1;
            $executions = [];
            $seenFingerprints = [];

            if ($previous !== []) {
                // Left by an escalation that this new run supersedes: lists are merged, not replaced.
                $store->mergeRequestContext($this->requestId, ['search' => null, 'extract' => null]);
            }

            $store->updateRequest($this->requestId, [
                'status' => ProductImageDiscoveryRequestStatus::Searching->value,
                'search_started_at' => gmdate('c'),
                'attempts' => $searchRun,
            ]);
        }

        $execution = null;

        foreach (SearchEscalation::pendingQueries($searchQueries, $executions, SearchEscalation::familiesTried($executions)) as $searchQuery) {
            $rawExecution = $manager->searchImages(ProviderSearchQueryData::fromArray([
                'client_id' => $identity->clientId,
                'brand' => $identity->brand,
                'model' => $identity->modelCode ?? $identity->description,
                'color' => $identity->colorName,
                'ean' => $identity->ean,
                'supplier_sku' => $identity->supplierSku,
                'query' => $searchQuery->query,
                'site' => $searchQuery->siteDomain,
                'limit' => 10,
                'metadata' => $searchQuery->toArray(),
            ]));

            $execution = $this->discardResultsMissingIdentifier($searchQuery, $identity, $rawExecution);
            $discardedResults = $rawExecution->results->count() - $execution->results->count();
            $keptResults = $execution->results->count();
            $execution = $this->discardResultsAlreadySeen($execution, $seenFingerprints);

            $executions[] = [
                'search_query' => $searchQuery->toArray(),
                'execution' => $execution->toArray(),
                'discarded_results' => $discardedResults,
                'seen_results' => $keptResults - $execution->results->count(),
                'step' => $step,
            ];

            if ($discardedResults > 0) {
                $logger->record('pipeline.search.results_discarded', [
                    'intent' => $searchQuery->intent,
                    'query' => $searchQuery->query,
                    'discarded_results' => $discardedResults,
                    'kept_results' => $keptResults,
                ], requestId: $this->requestId);
            }

            if (! $execution->results->isEmpty()) {
                break;
            }
        }

        if ($this->continuesRun === null) {
            $execution ??= $manager->searchImages(ProviderSearchQueryData::fromArray([
                'client_id' => $identity->clientId,
                'query' => $identity->ean ?? $identity->supplierSku ?? $identity->modelCode ?? '',
            ]));
        } else {
            $execution ??= new SearchProviderExecutionResult(provider: null, results: new SearchResultCollection([]));
        }

        $found = ! $execution->results->isEmpty();
        // A step that finds no new image leaves the status to handleRejectedRun() below.
        $status = match (true) {
            $found => ProductImageDiscoveryRequestStatus::CandidatesFound,
            $this->continuesRun === null => ProductImageDiscoveryRequestStatus::NoCandidatesFound,
            default => null,
        };

        $store->updateRequest($this->requestId, array_filter([
            'status' => $status?->value,
            'search_completed_at' => gmdate('c'),
        ]));
        $store->mergeRequestContext($this->requestId, [
            'search' => [
                'run' => $searchRun,
                'step' => $step,
                'families_tried' => SearchEscalation::familiesTried($executions),
                'completed_at' => gmdate('c'),
                'queries' => array_map(static fn (SearchQueryData $query): array => $query->toArray(), $searchQueries),
                'execution' => $execution->toArray(),
                'executions' => $executions,
            ],
        ]);

        $logger->record('pipeline.search.completed', [
            'step' => $step,
            'results_count' => $execution->results->count(),
            'provider' => $execution->provider?->toSafeArray(),
            'attempts' => $execution->attempts,
            'used_fallback' => $execution->usedFallback,
        ], requestId: $this->requestId);

        if ($status !== null) {
            $this->dispatchIfPossible(new ExtractCandidateSourcesJob($this->requestId));

            return $store->getRequest($this->requestId) ?? $request;
        }

        // The run ends with the candidates of its earlier steps, all rejected.
        $this->handleRejectedRun($store, $logger);

        if ($this->enumValue($store->getRequest($this->requestId)['status'] ?? null) === ProductImageDiscoveryRequestStatus::Searching->value) {
            $store->updateRequest($this->requestId, [
                'status' => ProductImageDiscoveryRequestStatus::ManualReview->value,
            ]);
        }

        return $store->getRequest($this->requestId) ?? $request;
    }

    /**
     * @param array<string, true> $seenFingerprints
     */
    private function discardResultsAlreadySeen(SearchProviderExecutionResult $execution, array $seenFingerprints): SearchProviderExecutionResult
    {
        if ($seenFingerprints === []) {
            return $execution;
        }

        $kept = array_values(array_filter(
            $execution->results->all(),
            static fn (SearchResult $result): bool => ! isset($seenFingerprints[$result->fingerprint()]),
        ));

        if (count($kept) === $execution->results->count()) {
            return $execution;
        }

        return new SearchProviderExecutionResult(
            provider: $execution->provider,
            results: new SearchResultCollection($kept),
            attempts: $execution->attempts,
            usedFallback: $execution->usedFallback,
        );
    }

    /**
     * Fuzzy providers return unrelated results even for identifiers that are not
     * indexed anywhere; an identifier-driven query must only win the "first
     * non-empty query" race with results that actually mention the identifier.
     *
     * Only the EAN is checked: image providers such as Brave return the retailer title and
     * URLs, which almost never carry the supplier SKU or model code, so filtering on those
     * dropped the right product pages too. Codes are weighed by ScoreCandidateImageAction.
     */
    private function discardResultsMissingIdentifier(
        SearchQueryData $searchQuery,
        ProductIdentityData $identity,
        SearchProviderExecutionResult $execution,
    ): SearchProviderExecutionResult {
        if ($identity->ean === null || ! in_array($searchQuery->intent, ['ean', 'site_ean'], true)) {
            return $execution;
        }

        $kept = array_values(array_filter(
            $execution->results->all(),
            fn (SearchResult $result): bool => $this->resultMentionsIdentifier($result, $identity->ean),
        ));

        if (count($kept) === $execution->results->count()) {
            return $execution;
        }

        return new SearchProviderExecutionResult(
            provider: $execution->provider,
            results: new SearchResultCollection($kept),
            attempts: $execution->attempts,
            usedFallback: $execution->usedFallback,
        );
    }

    private function resultMentionsIdentifier(SearchResult $result, string $identifier): bool
    {
        $needle = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $identifier));

        if ($needle === '') {
            return true;
        }

        $haystack = strtolower(implode(' ', array_filter([
            $result->title,
            $result->pageUrl,
            $result->imageUrl,
            $result->snippet,
            $result->sourceDomain,
            TextNormalizer::flattenStrings($result->providerMetadata),
        ], static fn (?string $part): bool => $part !== null && $part !== '')));

        return str_contains((string) preg_replace('/[^a-z0-9]+/', '', $haystack), $needle);
    }
}
