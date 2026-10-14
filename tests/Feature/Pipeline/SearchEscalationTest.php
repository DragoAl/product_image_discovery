<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use Illuminate\Support\Facades\Bus;
use Padosoft\ProductImageDiscovery\Jobs\DownloadCandidateImageJob;
use Padosoft\ProductImageDiscovery\Jobs\ExtractCandidateSourcesJob;
use Padosoft\ProductImageDiscovery\Jobs\IngestProductImageDiscoveryJob;
use Padosoft\ProductImageDiscovery\Jobs\SearchProductImageJob;
use Padosoft\ProductImageDiscovery\Jobs\VerifyCandidateImageJob;
use Padosoft\ProductImageDiscovery\Models\ProductImageDiscoveryCandidate;
use Padosoft\ProductImageDiscovery\Models\ProductImageDiscoveryEvent;
use Padosoft\ProductImageDiscovery\Models\ProductImageDiscoveryRequest;
use Padosoft\ProductImageDiscovery\Services\Logging\ProductImageEventLogger;
use Padosoft\ProductImageDiscovery\Services\Storage\EloquentPipelineStore;
use Padosoft\ProductImageDiscovery\Tests\Support\ScriptedSearchProvider;
use Padosoft\ProductImageDiscovery\Tests\TestCase;

final class SearchEscalationTest extends TestCase
{
    // Brave answers a quoted code it has not indexed with unrelated items of the brand: the
    // supplier SKU and the model code queries each bring back other camel products, only the
    // product name finds the bag.
    private const SUPPLIER_SKU = '"763435008IW2885 Camel"';

    private const MODEL_CODE = '"763435008IW2885"';

    private const NAME = 'Le 5 7 Bea';

    private EloquentPipelineStore $store;

    private ProductImageEventLogger $logger;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');
        $this->artisan('migrate')->run();
        Bus::fake();
        $this->store = new EloquentPipelineStore();
        $this->logger = app(ProductImageEventLogger::class);
    }

    public function test_a_rejected_run_searches_the_next_query_families_until_one_finds_the_product(): void
    {
        $provider = $this->provider(self::bag());
        $requestId = $this->ingest();

        // Step 1: the supplier SKU query wins the "first query with results" race with coats.
        $this->search($provider, $requestId);
        $this->extractAndVerify($requestId);

        $request = $this->store->getRequest($requestId);
        self::assertSame('searching', $request['status']);
        self::assertSame(['supplier_sku'], $request['context']['search']['families_tried']);
        self::assertNull($request['context']['search']['completed_at']);
        self::assertSame(2, $this->lastEvent('pipeline.search.escalated')['context']['step']);
        self::assertSame(1, $this->escalationsDispatched());

        // Step 2: the supplier SKU family is skipped; the model code brings other coats.
        $this->search($provider, $requestId, continuesRun: 1);
        $this->extractAndVerify($requestId);

        self::assertSame(['supplier_sku', 'model_code'], $this->store->getRequest($requestId)['context']['search']['families_tried']);
        self::assertSame(3, $this->lastEvent('pipeline.search.escalated')['context']['step']);

        // Step 3: only the description family is left, and it finds the bag.
        $this->search($provider, $requestId, continuesRun: 1);
        $request = $this->store->getRequest($requestId);

        self::assertSame('candidates_found', $request['status']);
        self::assertSame(3, $request['context']['search']['step']);
        self::assertSame(1, $request['context']['search']['run']);
        self::assertSame(['supplier_sku', 'model_code', 'description'], $request['context']['search']['families_tried']);
        self::assertCount(1, $request['context']['search']['execution']['results']);
        self::assertSame([
            '"Saint Laurent" "763435008IW2885 Camel" "Camel"',
            '"Saint Laurent" "763435008IW2885" "Camel"',
            '"Saint Laurent" "Le 5 7 Bea Suede Tote Bag" "Beige"',
        ], $provider->queries);
        self::assertSame([1, 2, 3], array_column($request['context']['search']['executions'], 'step'));

        (new ExtractCandidateSourcesJob($requestId))->handle($this->store, $this->logger);
        $bag = $this->candidateWithImage('https://shop.example.test/bea-camel.jpg');
        (new VerifyCandidateImageJob($requestId, $bag['id']))->handle($this->store, $this->logger);

        $request = $this->store->getRequest($requestId);
        self::assertSame('matched', $request['status']);
        self::assertSame('verified_match', $this->store->getCandidate($bag['id'])['status']);
        // Every step adds to the run: the coats stay rejected and are not verified again.
        self::assertCount(5, $request['context']['extract']['candidate_ids']);
        self::assertSame([1], ProductImageDiscoveryCandidate::query()->distinct()->pluck('search_run')->all());
        self::assertSame(5, ProductImageDiscoveryEvent::query()->where('event_type', 'pipeline.verify.completed')->count());
    }

    public function test_a_run_whose_last_step_finds_nothing_new_goes_to_manual_review_with_all_its_candidates(): void
    {
        // The product name only brings back the coats already rejected at step 1.
        $provider = $this->provider(self::coats('a'));
        $requestId = $this->ingest();

        $this->search($provider, $requestId);
        $this->extractAndVerify($requestId);
        $this->search($provider, $requestId, continuesRun: 1);
        $this->extractAndVerify($requestId);
        $this->search($provider, $requestId, continuesRun: 1);

        $request = $this->store->getRequest($requestId);
        $event = $this->lastEvent('pipeline.verify.all_rejected');

        self::assertSame('manual_review', $request['status']);
        self::assertNull($request['rejection_reason']);
        self::assertSame(4, $event['context']['candidate_count']);
        self::assertSame(3, $request['context']['search']['step']);
        self::assertSame(2, $request['context']['search']['executions'][3]['seen_results']);
        self::assertSame(2, $this->escalationsDispatched());
    }

    public function test_only_the_first_job_that_sees_the_run_rejected_starts_the_next_step(): void
    {
        $requestId = $this->ingest();
        $this->search($this->provider(self::bag()), $requestId);
        (new ExtractCandidateSourcesJob($requestId))->handle($this->store, $this->logger);
        $candidates = $this->store->listCandidates($requestId);
        (new VerifyCandidateImageJob($requestId, $candidates[0]['id']))->handle($this->store, $this->logger);
        // The second coat was promoted, then its download failed.
        $this->store->updateCandidate($candidates[1]['id'], ['status' => 'verified_match', 'image_url' => '']);

        (new DownloadCandidateImageJob($requestId, $candidates[1]['id']))->handle($this->store, $this->logger);
        // A second job of the same run reaches the same conclusion a moment later.
        (new DownloadCandidateImageJob($requestId, $candidates[1]['id']))->handle($this->store, $this->logger);

        self::assertSame('searching', $this->store->getRequest($requestId)['status']);
        self::assertSame(1, $this->escalationsDispatched());
    }

    public function test_a_stale_escalation_does_not_search(): void
    {
        $provider = $this->provider(self::bag());
        $requestId = $this->ingest();
        $this->search($provider, $requestId);
        $this->extractAndVerify($requestId);

        // A retry clears the pipeline context and queues the request before the step runs.
        $request = ProductImageDiscoveryRequest::query()->findOrFail($requestId);
        $request->forceFill(['status' => 'queued', 'raw_payload' => array_diff_key($request->raw_payload, ['context' => true])])->save();

        $this->search($provider, $requestId, continuesRun: 1);

        self::assertCount(1, $provider->queries);
        self::assertSame('queued', $this->store->getRequest($requestId)['status']);
    }

    public function test_the_step_budget_and_the_switch_are_honoured(): void
    {
        foreach ([['max_steps', 1], ['enabled', false]] as [$key, $value]) {
            config()->set('product-image-discovery.search.escalation.' . $key, $value);
            ProductImageDiscoveryRequest::query()->delete();
            $requestId = $this->ingest();

            $this->search($this->provider(self::bag()), $requestId);
            $this->extractAndVerify($requestId);

            self::assertSame('manual_review', $this->store->getRequest($requestId)['status'], $key);
            config()->set('product-image-discovery.search.escalation', ['enabled' => true, 'max_steps' => 4]);
        }

        self::assertSame(0, $this->escalationsDispatched());
    }

    private function ingest(): int|string
    {
        return (new IngestProductImageDiscoveryJob([
            'client_id' => 1,
            'erp_model_id' => 'gescat-221117',
            'erp_model_color_id' => 'gescat-221117',
            'brand' => 'Saint Laurent',
            'supplier' => 'Saint Laurent',
            'supplier_sku' => '763435008IW2885 Camel',
            'model_code' => '763435008IW2885',
            'color_code' => 'Camel',
            'color_name' => 'Beige',
            'name' => 'Le 5 7 Bea Suede Tote Bag',
        ]))->handle($this->store, $this->logger)['id'];
    }

    /**
     * @param list<array<string, mixed>> $nameResults
     */
    private function provider(array $nameResults): ScriptedSearchProvider
    {
        return new ScriptedSearchProvider([
            self::SUPPLIER_SKU => self::coats('a'),
            self::MODEL_CODE => self::coats('b'),
            self::NAME => $nameResults,
        ]);
    }

    private function search(ScriptedSearchProvider $provider, int|string $requestId, ?int $continuesRun = null): void
    {
        (new SearchProductImageJob($requestId, $continuesRun))->handle($this->store, $provider->manager(), $this->logger);
    }

    private function extractAndVerify(int|string $requestId): void
    {
        (new ExtractCandidateSourcesJob($requestId))->handle($this->store, $this->logger);

        foreach ($this->store->listCandidates($requestId) as $candidate) {
            if ($candidate['status'] === 'candidate') {
                (new VerifyCandidateImageJob($requestId, $candidate['id']))->handle($this->store, $this->logger);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function coats(string $set): array
    {
        return [
            [
                'title' => 'Saint Laurent Camel Cashmere Coat ' . $set,
                'page_url' => 'https://shop.example.test/saint-laurent-camel-coat-' . $set,
                'image_url' => 'https://shop.example.test/camel-coat-' . $set . '.jpg',
                'source_domain' => 'shop.example.test',
            ],
            [
                'title' => 'Saint Laurent Camel Suede Loafers ' . $set,
                'page_url' => 'https://shop.example.test/saint-laurent-camel-loafers-' . $set,
                'image_url' => 'https://shop.example.test/camel-loafers-' . $set . '.jpg',
                'source_domain' => 'shop.example.test',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function bag(): array
    {
        return [[
            'title' => 'Saint Laurent Le 5 7 Bea suede tote bag 763435008IW2885',
            'page_url' => 'https://shop.example.test/saint-laurent-le-5-a-7-bea-763435008iw2885-camel',
            'image_url' => 'https://shop.example.test/bea-camel.jpg',
            'source_domain' => 'shop.example.test',
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateWithImage(string $imageUrl): array
    {
        $candidate = ProductImageDiscoveryCandidate::query()->where('image_url', $imageUrl)->sole();

        return $this->store->getCandidate($candidate->getKey()) ?? [];
    }

    private function escalationsDispatched(): int
    {
        // Ingest queues the first search too: only the jobs that continue a run are escalations.
        return Bus::dispatched(
            SearchProductImageJob::class,
            static fn (SearchProductImageJob $job): bool => (function (): ?int {
                return $this->continuesRun;
            })->call($job) !== null,
        )->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function lastEvent(string $type): array
    {
        $event = ProductImageDiscoveryEvent::query()->where('event_type', $type)->orderByDesc('id')->first();

        self::assertNotNull($event, "No {$type} event.");

        return $event->toArray();
    }
}
