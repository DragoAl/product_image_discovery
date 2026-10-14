<?php

declare(strict_types=1);

use Padosoft\ProductImageDiscovery\Actions\ScoreCandidateImageAction;
use Padosoft\ProductImageDiscovery\DTO\CandidateScoreData;
use Padosoft\ProductImageDiscovery\DTO\ProductIdentityData;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/src/Services/Support/TextNormalizer.php';
require_once dirname(__DIR__, 3) . '/src/Services/Support/DomainNormalizer.php';
require_once dirname(__DIR__, 3) . '/src/DTO/ProductIdentityData.php';
require_once dirname(__DIR__, 3) . '/src/DTO/CandidateImageData.php';
require_once dirname(__DIR__, 3) . '/src/DTO/CandidateScoreData.php';
require_once dirname(__DIR__, 3) . '/src/Actions/ScoreCandidateImageAction.php';

/**
 * ai_confirms_identifier_match and tolerate_adjacent_colors are off by default: scores stay the
 * same until a setting turns them on.
 */
final class ScoringOptInRulesTest extends TestCase
{
    private const AI_CONFIRMS = [
        'available' => true,
        'status' => 'completed',
        'confidence' => 95,
        'match' => true,
        'variant_safe' => true,
        'brand_match' => true,
        'model_match' => true,
        'product_type_match' => true,
        'color_match' => true,
    ];

    private const BOTH_ON = ['ai_confirms_identifier_match' => true, 'tolerate_adjacent_colors' => true];

    public function test_an_ai_confirmation_of_a_page_with_the_code_reaches_the_candidate_threshold_only_when_enabled(): void
    {
        $candidate = $this->fashionClinic(self::AI_CONFIRMS);

        $off = $this->score($candidate);
        $on = $this->score($candidate, ['ai_confirms_identifier_match' => true]);

        self::assertSame(14, $off->visualMatchScore);
        self::assertSame(44, $off->finalScore);
        self::assertSame('low_score_rejected', $off->status);
        self::assertSame(33, $on->visualMatchScore);
        self::assertSame(63, $on->finalScore);
        self::assertSame('candidate', $on->status);
        self::assertContains('ai_confirms_identifier_match', $on->evidence['matches']);
    }

    public function test_settings_stored_as_strings_turn_the_rules_on(): void
    {
        self::assertSame('candidate', $this->score($this->fashionClinic(self::AI_CONFIRMS), ['ai_confirms_identifier_match' => 'true'])->status);
        self::assertSame('low_score_rejected', $this->score($this->fashionClinic(self::AI_CONFIRMS), ['ai_confirms_identifier_match' => '0'])->status);
    }

    public function test_an_ai_confirmation_without_the_code_on_the_page_keeps_its_usual_weight(): void
    {
        $score = $this->score([
            'source_page_url' => 'https://www.endource.com/product/saint-laurent-le-5-a-7-bea-suede-tote-bag/5q5svld9yumr',
            'image_url' => 'https://cdn.endource.com/image/direct/848227909afd/saint-laurent-suede-bea-tote-bag.jpg',
            'title' => 'Taupe suede tote bag with YSL signature ribbon closure.',
            'ai_analysis' => ['verification' => self::AI_CONFIRMS],
        ], self::BOTH_ON);

        self::assertSame(14, $score->visualMatchScore);
        self::assertSame('low_score_rejected', $score->status);
        self::assertNotContains('ai_confirms_identifier_match', $score->evidence['matches']);
    }

    public function test_an_ai_color_mismatch_still_rejects_a_page_with_the_code(): void
    {
        $score = $this->score($this->fashionClinic(['color_match' => false, 'variant_safe' => false] + self::AI_CONFIRMS), self::BOTH_ON);

        self::assertSame(0, $score->visualMatchScore);
        self::assertSame('WRONG_COLOR', $score->rejectionReason);
    }

    public function test_an_adjacent_shade_in_the_title_is_not_a_color_mismatch_only_when_enabled(): void
    {
        $lordAndTaylor = [
            'source_page_url' => 'https://lordandtaylor.com/products/saint-laurent-le-5-a-7-bea-tote-bag-brown-suede',
            'image_url' => 'https://lordandtaylor.com/cdn/shop/files/NTE3MTgw_d68e9c8c.jpg?v=1779496221',
            'title' => 'Brown suede Saint Laurent Le 5 à 7 Bea tote bag, front view',
        ];

        $off = $this->score($lordAndTaylor);
        $on = $this->score($lordAndTaylor, ['tolerate_adjacent_colors' => true]);

        self::assertTrue($off->colorMismatch);
        self::assertSame('WRONG_COLOR', $off->rejectionReason);
        self::assertFalse($on->colorMismatch);
        self::assertContains('color_name_adjacent', $on->evidence['matches']);
        self::assertNotContains('color_name', $on->evidence['matches']);
        self::assertSame($off->textualMatchScore, $on->textualMatchScore);
    }

    public function test_a_color_outside_the_adjacent_group_is_still_a_mismatch(): void
    {
        $black = [
            'source_page_url' => 'https://shop.example/p/saint-laurent-bea-black',
            'title' => 'Saint Laurent Le 5 à 7 Bea tote bag in black suede',
        ];

        self::assertTrue($this->score($black, self::BOTH_ON)->colorMismatch);
        // A shop name for a brown shade is a mismatch for a black product once it is read.
        $identity = ProductIdentityData::fromArray(['brand' => 'Saint Laurent', 'model_code' => '763435008IW2885', 'color_name' => 'Black']);
        $cognac = ['source_page_url' => 'https://shop.example/p/bea', 'title' => 'Saint Laurent Bea tote in cognac suede'];

        self::assertFalse((new ScoreCandidateImageAction())->handle($identity, $cognac)->colorMismatch);
        self::assertTrue((new ScoreCandidateImageAction())->handle($identity, $cognac, [], self::BOTH_ON)->colorMismatch);
    }

    public function test_an_adjacent_structured_color_is_not_a_mismatch_when_enabled(): void
    {
        $candidate = [
            'source_page_url' => 'https://shop.example/p/bea',
            'structured_data' => ['brand' => 'Saint Laurent', 'mpn' => '763435008IW2885', 'color' => 'Caramel Cognac'],
        ];

        self::assertTrue($this->score($candidate)->colorMismatch);
        self::assertFalse($this->score($candidate, ['tolerate_adjacent_colors' => true])->colorMismatch);
    }

    /**
     * @param array<string, mixed> $ai
     * @return array<string, mixed>
     */
    private function fashionClinic(array $ai): array
    {
        return [
            'source_page_url' => 'https://fashionclinic.com/products/saint-laurent-caramel-cognac-le-5-a-7-bea-suede-tote-763435008iw2885',
            'image_url' => 'https://fashionclinic.com/cdn/shop/files/763435008IW.2885_06.jpg?v=1747414057',
            'title' => 'Saint Laurent Caramel Cognac Le 5 à 7 Bea Suede Tote',
            'ai_analysis' => ['verification' => $ai],
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $settings
     */
    private function score(array $candidate, array $settings = []): CandidateScoreData
    {
        $identity = ProductIdentityData::fromArray([
            'brand' => 'Saint Laurent',
            'supplier_sku' => '763435008IW2885 Camel',
            'model_code' => '763435008IW2885',
            'color_code' => 'Camel',
            'color_name' => 'Beige',
        ]);

        return (new ScoreCandidateImageAction())->handle($identity, $candidate, [], $settings);
    }
}
