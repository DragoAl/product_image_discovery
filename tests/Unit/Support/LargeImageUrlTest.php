<?php

declare(strict_types=1);

namespace Padosoft\ProductImageDiscovery\Tests\Unit\Support;

use Padosoft\ProductImageDiscovery\DTO\SearchResultData;
use Padosoft\ProductImageDiscovery\Services\Support\LargeImageUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LargeImageUrlTest extends TestCase
{
    #[DataProvider('urls')]
    public function test_it_asks_shopify_for_a_large_image_only(?string $url, ?string $expected): void
    {
        self::assertSame($expected, LargeImageUrl::resolve($url));
    }

    /**
     * @return array<string, array{0:?string, 1:?string}>
     */
    public static function urls(): array
    {
        return [
            'shop domain thumbnail' => [
                'https://fashionclinic.com/cdn/shop/files/763435008IW.2885_06.jpg?v=1747414057&width=165',
                'https://fashionclinic.com/cdn/shop/files/763435008IW.2885_06.jpg?v=1747414057&width=2048',
            ],
            'height and crop' => [
                'https://shop.example/cdn/shop/products/bag.jpg?width=165&height=220&crop=center&v=1',
                'https://shop.example/cdn/shop/products/bag.jpg?v=1&width=2048',
            ],
            'already large' => [
                'https://lordandtaylor.com/cdn/shop/files/NTE3MTgw_d68e9c8c.jpg?v=1779496221&width=2160',
                'https://lordandtaylor.com/cdn/shop/files/NTE3MTgw_d68e9c8c.jpg?v=1779496221&width=2160',
            ],
            'original upload' => [
                'https://shop.example/cdn/shop/files/bag.jpg?v=1',
                'https://shop.example/cdn/shop/files/bag.jpg?v=1',
            ],
            'legacy size suffix' => [
                'https://cdn.shopify.com/s/files/1/0001/products/bag_165x220@2x.jpg?v=1',
                'https://cdn.shopify.com/s/files/1/0001/products/bag_2048x.jpg?v=1',
            ],
            'legacy named size' => [
                'https://cdn.shopify.com/s/files/1/0001/products/bag_grande.png',
                'https://cdn.shopify.com/s/files/1/0001/products/bag_2048x.png',
            ],
            'file name number is not a size' => [
                'https://shop.example/cdn/shop/files/763435008IW.2885_06.jpg',
                'https://shop.example/cdn/shop/files/763435008IW.2885_06.jpg',
            ],
            'other cdn' => [
                'https://a.1stdibscdn.com/coat/v_28876212_bg_processed.jpg?width=240',
                'https://a.1stdibscdn.com/coat/v_28876212_bg_processed.jpg?width=240',
            ],
            'null' => [null, null],
        ];
    }

    public function test_a_resized_candidate_drops_the_thumbnail_size(): void
    {
        $result = SearchResultData::fromArray([
            'provider' => 'brave',
            'source_page_url' => 'https://fashionclinic.com/products/bea-tote-763435008iw2885',
            'image_url' => 'https://fashionclinic.com/cdn/shop/files/763435008IW.2885_06.jpg?v=1&width=165',
            'width' => 165,
            'height' => 220,
        ]);
        $candidate = $result->toCandidate();

        self::assertSame('https://fashionclinic.com/cdn/shop/files/763435008IW.2885_06.jpg?v=1&width=2048', $candidate->imageUrl);
        self::assertNull($candidate->width);
        self::assertNull($candidate->height);
        // The evidence keeps what the provider returned.
        self::assertSame($result->imageUrl, $candidate->evidence['search_result']['image_url']);

        $kept = SearchResultData::fromArray(['provider' => 'brave', 'image_url' => 'https://i.ebayimg.com/images/g/x/s-l1200.webp', 'width' => 900, 'height' => 1200])->toCandidate();

        self::assertSame(900, $kept->width);
    }
}
