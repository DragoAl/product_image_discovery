<?php

declare(strict_types=1);

namespace Padosoft\ProductImageDiscovery\Tests\Unit\Support;

use Padosoft\ProductImageDiscovery\DTO\SearchQueryData;
use Padosoft\ProductImageDiscovery\Services\Support\SearchEscalation;
use PHPUnit\Framework\TestCase;

final class SearchEscalationTest extends TestCase
{
    public function test_queries_are_grouped_by_the_identifier_they_search(): void
    {
        $families = array_map(
            static fn (string $intent): string => (new SearchQueryData('q', $intent, 1))->family(),
            [
                'ean', 'site_ean',
                'supplier_sku_color_code', 'supplier_sku_color_name', 'supplier_sku', 'site_supplier_sku',
                'model_code_color_code', 'model_code_description', 'model_code', 'season_model_code', 'site_model_code',
                'description_color', 'description',
                'fallback',
            ],
        );

        self::assertSame([
            'ean', 'ean',
            'supplier_sku', 'supplier_sku', 'supplier_sku', 'supplier_sku',
            'model_code', 'model_code', 'model_code', 'model_code', 'model_code',
            'description', 'description',
            'fallback',
        ], $families);
    }

    public function test_pending_queries_skip_executed_queries_and_exhausted_families(): void
    {
        $queries = [
            new SearchQueryData('"B" "SKU 1" "Red"', 'supplier_sku_color_code', 10),
            new SearchQueryData('"B" "SKU" "Red"', 'model_code_color_code', 11),
            new SearchQueryData('"B" "SKU 1"', 'supplier_sku', 12),
            new SearchQueryData('"B" "SKU"', 'model_code', 13),
            new SearchQueryData('"B" Bag', 'description', 14),
        ];
        $executions = [
            ['search_query' => ['query' => '"B" "SKU 1" "Red"']],
            ['search_query' => ['query' => '"b" "sku" "red"']],
        ];

        $pending = SearchEscalation::pendingQueries($queries, $executions, ['supplier_sku']);

        self::assertSame(['model_code', 'description'], array_map(static fn (SearchQueryData $query): string => $query->intent, $pending));
    }

    public function test_the_next_step_needs_a_run_steps_left_and_a_pending_query(): void
    {
        $sku = new SearchQueryData('"B" "SKU 1"', 'supplier_sku', 10);
        $bag = new SearchQueryData('"B" Bag', 'description', 11);
        // As recorded before steps were tracked: no step, no families_tried.
        $search = [
            'run' => 1,
            'queries' => [$sku->toArray(), $bag->toArray()],
            'executions' => [['search_query' => $sku->toArray(), 'execution' => ['results' => [['title' => 'Coat']]]]],
        ];

        self::assertSame(['supplier_sku'], SearchEscalation::familiesTried($search['executions']));
        self::assertSame(2, SearchEscalation::nextStep($search));
        self::assertNull(SearchEscalation::nextStep(['step' => 4] + $search));
        self::assertNull(SearchEscalation::nextStep(['executions' => [
            ...$search['executions'],
            ['search_query' => $bag->toArray(), 'execution' => ['results' => []]],
        ]] + $search));
        // A run without recorded queries has nothing to escalate to.
        self::assertNull(SearchEscalation::nextStep(['run' => 1]));
        self::assertNull(SearchEscalation::nextStep([]));
    }
}
