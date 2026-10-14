<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use Illuminate\Support\Facades\Bus;
use Padosoft\ProductImageDiscovery\Jobs\ExtractCandidateSourcesJob;
use Padosoft\ProductImageDiscovery\Jobs\IngestProductImageDiscoveryJob;
use Padosoft\ProductImageDiscovery\Jobs\SearchProductImageJob;
use Padosoft\ProductImageDiscovery\Jobs\VerifyCandidateImageJob;
use Padosoft\ProductImageDiscovery\Models\ProductImageDiscoverySetting;
use Padosoft\ProductImageDiscovery\Services\Logging\ProductImageEventLogger;
use Padosoft\ProductImageDiscovery\Services\Storage\EloquentPipelineStore;
use Padosoft\ProductImageDiscovery\Tests\Support\ScriptedSearchProvider;
use Padosoft\ProductImageDiscovery\Tests\TestCase;

/**
 * The Saint Laurent "Le 5 à 7 Bea" tote searched in production (Gescat article 221117): the
 * fixture holds what Brave returned for each query it answered. The code is only in the
 * fashionclinic.com URLs, the shops call the color "Caramel Cognac" or "Brown" while Gescat says
 * "Camel" / "Beige", and the other results are camel coats, a dress and beige bags.
 */
final class RealSearchResultsTest extends TestCase
{
    private const AI_CONFIRMS = [
        'available' => true, 'status' => 'completed', 'confidence' => 95, 'match' => true, 'variant_safe' => true,
        'brand_match' => true, 'model_match' => true, 'product_type_match' => true, 'color_match' => true,
    ];

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

    public function test_without_the_ai_the_bag_now_ranks_above_the_camel_coats_but_is_not_accepted(): void
    {
        $requestId = $this->discover(ai: null);
        $candidates = $this->rankedCandidates($requestId);

        self::assertSame('manual_review', $this->store->getRequest($requestId)['status']);
        self::assertSame(['model_code', 'description'], $this->store->getRequest($requestId)['context']['search']['families_tried']);
        self::assertStringStartsWith('https://fashionclinic.com/', $candidates[0]['source_page_url']);
        self::assertContains('model_code', $candidates[0]['evidence']['matches']);
        self::assertSame(30, $candidates[0]['final_score']);
        self::assertSame(22, $candidates[4]['final_score']);
        self::assertSame([], array_filter($candidates, static fn (array $candidate): bool => $candidate['status'] === 'verified_match'));
    }

    public function test_an_ai_confirmation_alone_stays_one_point_short(): void
    {
        $requestId = $this->discover(ai: self::AI_CONFIRMS);

        self::assertSame('manual_review', $this->store->getRequest($requestId)['status']);
        self::assertSame(44, $this->rankedCandidates($requestId)[0]['final_score']);
    }

    public function test_with_the_opt_in_rules_the_ai_confirmed_shop_images_are_accepted_at_a_large_size(): void
    {
        $requestId = $this->discover(ai: self::AI_CONFIRMS, settings: ['ai_confirms_identifier_match' => true, 'tolerate_adjacent_colors' => true]);
        $request = $this->store->getRequest($requestId);
        $accepted = array_values(array_filter($this->rankedCandidates($requestId), static fn (array $candidate): bool => $candidate['status'] === 'verified_match'));

        self::assertSame('matched', $request['status']);
        self::assertSame(1, $request['context']['search']['step']);
        self::assertCount(4, $accepted);

        foreach ($accepted as $candidate) {
            self::assertSame(63, $candidate['final_score']);
            self::assertStringStartsWith('https://fashionclinic.com/cdn/shop/files/763435008IW.2885_', $candidate['image_url']);
            self::assertStringEndsWith('&width=2048', $candidate['image_url']);
            self::assertNull($candidate['width']);
        }
    }

    public function test_an_ai_color_mismatch_keeps_the_request_in_review(): void
    {
        $requestId = $this->discover(
            ai: ['color_match' => false, 'variant_safe' => false] + self::AI_CONFIRMS,
            settings: ['ai_confirms_identifier_match' => true, 'tolerate_adjacent_colors' => true],
        );

        self::assertSame('manual_review', $this->store->getRequest($requestId)['status']);
        self::assertSame([], array_filter($this->rankedCandidates($requestId), static fn (array $candidate): bool => $candidate['status'] === 'verified_match'));
    }

    public function test_a_brown_title_is_no_longer_a_wrong_color_with_adjacent_colors(): void
    {
        $lordAndTaylor = static fn (array $candidates): array => array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => str_starts_with($candidate['source_page_url'], 'https://lordandtaylor.com/'),
        ))[0];

        $off = $lordAndTaylor($this->rankedCandidates($this->discover(ai: null)));
        $this->store = new EloquentPipelineStore();
        $on = $lordAndTaylor($this->rankedCandidates($this->discover(ai: null, settings: ['tolerate_adjacent_colors' => true])));

        self::assertSame('WRONG_COLOR', $off['rejection_reason']);
        self::assertSame('SOURCE_NOT_ALLOWED', $on['rejection_reason']);
        self::assertContains('color_name_adjacent', $on['evidence']['matches']);
    }

    /**
     * Search, extract and verify every step of the run, the way the queue would. The AI verdict
     * is given for the bag; the other products are seen as other products.
     *
     * @param array<string, mixed>|null $ai
     * @param array<string, mixed> $settings
     */
    private function discover(?array $ai, array $settings = []): int|string
    {
        ProductImageDiscoverySetting::query()->delete();

        foreach ($settings as $key => $value) {
            ProductImageDiscoverySetting::query()->create(['setting_key' => $key, 'setting_value' => $value, 'value_type' => 'boolean', 'is_active' => true]);
        }

        $provider = new ScriptedSearchProvider(json_decode(
            (string) file_get_contents(__DIR__ . '/Fixtures/saint-laurent-bea-tote-brave-results.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        ));
        $requestId = (new IngestProductImageDiscoveryJob([
            'client_id' => 1,
            'erp_model_id' => 'gescat-221117-' . uniqid(),
            'brand' => 'Saint Laurent',
            'supplier' => 'Saint Laurent',
            'supplier_sku' => '763435008IW2885 Camel',
            'model_code' => '763435008IW2885',
            'color_code' => 'Camel',
            'color_name' => 'Beige',
            'name' => 'Le 5  7 Bea Suede Tote Bag',
        ]))->handle($this->store, $this->logger)['id'];
        $continuesRun = null;

        do {
            (new SearchProductImageJob($requestId, $continuesRun))->handle($this->store, $provider->manager(), $this->logger);

            if ($this->store->getRequest($requestId)['status'] !== 'candidates_found') {
                break;
            }

            (new ExtractCandidateSourcesJob($requestId))->handle($this->store, $this->logger);

            foreach ($this->store->listCandidates($requestId) as $candidate) {
                if ($candidate['status'] !== 'candidate') {
                    continue;
                }

                if ($ai !== null) {
                    $isBag = str_contains($candidate['source_page_url'], 'bea');
                    $verdict = $isBag ? $ai : ['model_match' => false, 'product_type_match' => false, 'match' => false, 'variant_safe' => false] + self::AI_CONFIRMS;
                    $this->store->updateCandidate($candidate['id'], ['ai_analysis' => ['verification' => $verdict]]);
                }

                (new VerifyCandidateImageJob($requestId, $candidate['id']))->handle($this->store, $this->logger);
            }

            $request = $this->store->getRequest($requestId);
            $continuesRun = (int) $request['context']['search']['run'];
        } while ($request['status'] === 'searching');

        return $requestId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rankedCandidates(int|string $requestId): array
    {
        $candidates = $this->store->listCandidates($requestId);
        usort($candidates, static fn (array $a, array $b): int => $b['final_score'] <=> $a['final_score']);

        return $candidates;
    }
}
