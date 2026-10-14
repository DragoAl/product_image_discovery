<?php

declare(strict_types=1);

namespace Padosoft\ProductImageDiscovery\Services\Support;

/**
 * Image search returns the size the page showed, often a thumbnail. CDNs that resize on the fly
 * serve the same image larger: 2048 px wide is above the quality minimum and stays small enough
 * for an AI provider to fetch, unlike an original upload of several megabytes.
 */
final class LargeImageUrl
{
    public const WIDTH = 2048;

    /** Shopify resize parameters, on cdn.shopify.com and on a shop's own domain (/cdn/shop/). */
    private const SHOPIFY_SIZE_PARAMETERS = ['width', 'height', 'crop', 'pad_color'];

    /** Legacy Shopify size suffix of the file name: "bag_165x220.jpg", "bag_small@2x.jpg". */
    private const SHOPIFY_SIZE_SUFFIX = '/_(?:(\d+)x\d*|x\d+|pico|icon|thumb|small|compact|medium|large|grande)(?:_crop_[a-z]+)?(?:@\dx)?(?=\.[a-z0-9]+$)/i';

    public static function resolve(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return $url;
        }

        if (strtolower($parts['host']) !== 'cdn.shopify.com' && ! str_contains($parts['path'], '/cdn/shop/')) {
            return $url;
        }

        $path = preg_replace_callback(
            self::SHOPIFY_SIZE_SUFFIX,
            static fn (array $match): string => '_' . max(self::WIDTH, (int) ($match[1] ?? 0)) . 'x',
            $parts['path'],
        ) ?? $parts['path'];

        $kept = [];
        $sizes = [];

        foreach (array_filter(explode('&', $parts['query'] ?? '')) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = strtolower(urldecode($name));

            if (in_array($name, self::SHOPIFY_SIZE_PARAMETERS, true)) {
                $sizes[$name] = $value;
            } else {
                $kept[] = $pair;
            }
        }

        $width = (int) ($sizes['width'] ?? 0);

        // Already large, or not resized at all (the original upload): nothing to change.
        if ($path === $parts['path'] && ($sizes === [] || (array_keys($sizes) === ['width'] && $width >= self::WIDTH))) {
            return $url;
        }

        if ($sizes !== []) {
            $kept[] = 'width=' . max(self::WIDTH, $width);
        }

        return $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . $path
            . ($kept !== [] ? '?' . implode('&', $kept) : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }
}
