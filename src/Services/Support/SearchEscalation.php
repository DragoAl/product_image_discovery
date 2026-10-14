<?php

declare(strict_types=1);

namespace Padosoft\ProductImageDiscovery\Services\Support;

use Padosoft\ProductImageDiscovery\DTO\SearchQueryData;

/**
 * A search run is made of steps. Step 1 runs the queries in order and keeps the results of the
 * first one that returns any, as before. When every candidate of the run is then rejected, the
 * run escalates: the next step skips the queries already executed and the families (ean,
 * supplier_sku, model_code, description) whose results were all rejected, so an identifier that
 * is not indexed (the provider answers with unrelated items) no longer hides the other ones.
 */
final class SearchEscalation
{
    public const DEFAULT_MAX_STEPS = 4;

    /**
     * Families whose results were all rejected: those of the queries that won a step, i.e. the
     * executions that kept results. Works for runs recorded before steps were tracked too.
     *
     * @param list<array<string, mixed>> $executions context.search.executions of the run
     * @return list<string>
     */
    public static function familiesTried(array $executions): array
    {
        $families = [];

        foreach ($executions as $entry) {
            if (is_array($entry['search_query'] ?? null) && ($entry['execution']['results'] ?? []) !== []) {
                $families[] = SearchQueryData::fromArray($entry['search_query'])->family();
            }
        }

        return array_values(array_unique($families));
    }

    /**
     * Queries a step may still execute, in their original order.
     *
     * @param list<SearchQueryData> $queries
     * @param list<array<string, mixed>> $executions context.search.executions of the run
     * @param list<string> $exhaustedFamilies
     * @return list<SearchQueryData>
     */
    public static function pendingQueries(array $queries, array $executions, array $exhaustedFamilies): array
    {
        $executed = [];

        foreach ($executions as $entry) {
            $query = $entry['search_query']['query'] ?? null;

            if (is_string($query)) {
                $executed[strtolower($query)] = true;
            }
        }

        return array_values(array_filter(
            $queries,
            static fn (SearchQueryData $query): bool => ! isset($executed[strtolower($query->query)])
                && ! in_array($query->family(), $exhaustedFamilies, true),
        ));
    }

    /**
     * Number of the step that should follow the request's current one once all its candidates
     * are rejected, or null when escalation is off, the step budget is spent or no query is left.
     *
     * @param array<string, mixed> $search context.search of the request
     */
    public static function nextStep(array $search): ?int
    {
        if (! self::enabled() || ! is_numeric($search['run'] ?? null)) {
            return null;
        }

        $step = max(1, (int) ($search['step'] ?? 1));

        if ($step >= self::maxSteps()) {
            return null;
        }

        $queries = array_map(
            static fn (array $query): SearchQueryData => SearchQueryData::fromArray($query),
            array_values(array_filter($search['queries'] ?? [], 'is_array')),
        );
        $executions = array_values(array_filter($search['executions'] ?? [], 'is_array'));
        $pending = self::pendingQueries($queries, $executions, self::familiesTried($executions));

        return $pending === [] ? null : $step + 1;
    }

    /**
     * Verify jobs of one run finish in parallel: only the first one to see the run rejected may
     * start the next step. Without a cache (standalone pipeline) there is nothing to race with.
     */
    public static function claim(int|string $requestId, int $run, int $step): bool
    {
        try {
            return (bool) cache()->add("product-image-discovery:search-escalation:{$requestId}:{$run}:{$step}", true, 86400);
        } catch (\Throwable) {
            return true;
        }
    }

    private static function enabled(): bool
    {
        $enabled = self::config('product-image-discovery.search.escalation.enabled', true);

        return filter_var($enabled, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    private static function maxSteps(): int
    {
        $maxSteps = self::config('product-image-discovery.search.escalation.max_steps', self::DEFAULT_MAX_STEPS);

        return is_numeric($maxSteps) ? max(1, (int) $maxSteps) : self::DEFAULT_MAX_STEPS;
    }

    private static function config(string $key, mixed $default): mixed
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            return config($key) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
