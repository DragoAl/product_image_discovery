<?php

declare(strict_types=1);

namespace Padosoft\ProductImageDiscovery\Actions;

use Padosoft\ProductImageDiscovery\DTO\ProductIdentityData;
use Padosoft\ProductImageDiscovery\DTO\SearchQueryData;
use Padosoft\ProductImageDiscovery\Services\Support\DomainNormalizer;
use Padosoft\ProductImageDiscovery\Services\Support\TextNormalizer;

final class GenerateSearchQueriesAction
{
    /**
     * @param list<array<string, mixed>> $trustedSources
     * @param array<string, mixed> $options
     * @return list<SearchQueryData>
     */
    public function handle(ProductIdentityData $identity, array $trustedSources = [], array $options = []): array
    {
        $maxQueries = max(1, (int) ($options['max_queries'] ?? 10));
        $supportsSiteFilter = (bool) ($options['supports_site_filter'] ?? true);
        $allowGenericWithoutStrongIdentifier = (bool) ($options['allow_generic_without_strong_identifier'] ?? false);

        $queries = [];
        $seen = [];
        $priority = 10;

        $add = function (?string $query, string $intent, int $weight, string $type = 'image', ?string $siteDomain = null, array $metadata = []) use (&$queries, &$seen, &$priority): void {
            $query = TextNormalizer::nullableString($query);

            if ($query === null) {
                return;
            }

            $key = strtolower($query);

            if (isset($seen[$key])) {
                return;
            }

            $seen[$key] = true;
            $queries[] = new SearchQueryData(
                query: $query,
                intent: $intent,
                priority: $priority++,
                weight: $weight,
                type: $type,
                siteDomain: $siteDomain,
                metadata: $metadata,
            );
        };

        $brand = $this->quote($identity->brand);
        $supplierSku = $this->supplierSkuAddsOnlyAColorWord($identity) ? null : $identity->supplierSku;

        if ($identity->ean !== null) {
            $add($brand . ' ' . $this->quote($identity->ean), 'ean', 100);
        }

        if ($supplierSku !== null && $identity->colorCode !== null) {
            $add($brand . ' ' . $this->quote($supplierSku) . ' ' . $this->quote($identity->colorCode), 'supplier_sku_color_code', 99);
        }

        if ($supplierSku !== null && $identity->colorName !== null) {
            $add($brand . ' ' . $this->quote($supplierSku) . ' ' . $this->quote($identity->colorName), 'supplier_sku_color_name', 98);
        }

        if ($identity->modelCode !== null && $identity->colorCode !== null) {
            $add($brand . ' ' . $this->quote($identity->modelCode) . ' ' . $this->quote($identity->colorCode), 'model_code_color_code', 97);
        }

        if ($identity->modelCode !== null && $identity->colorName !== null) {
            $add($brand . ' ' . $this->quote($identity->modelCode) . ' ' . $this->quote($identity->colorName), 'model_code_color_name', 96);
        }

        if ($supplierSku !== null) {
            $add($brand . ' ' . $this->quote($supplierSku), 'supplier_sku', 90);
        }

        // Colorless fallbacks: a color code like "001" is shared by unrelated products of the same
        // brand, so the color-aware queries above can come back with only wrong items. The product
        // name is left unquoted because retailers rarely reuse the ERP name verbatim.
        if ($identity->modelCode !== null && $identity->description !== null) {
            $add($brand . ' ' . $this->quote($identity->modelCode) . ' ' . $identity->description, 'model_code_description', 85);
        }

        if ($identity->modelCode !== null) {
            $add($brand . ' ' . $this->quote($identity->modelCode), 'model_code', 80);
        }

        if ($identity->hasStrongIdentifier() || $allowGenericWithoutStrongIdentifier) {
            if ($identity->description !== null && $identity->colorName !== null) {
                $add($brand . ' ' . $this->quote($identity->description) . ' ' . $this->quote($identity->colorName), 'description_color', 40);
            }

            if ($identity->season !== null && $identity->modelCode !== null) {
                $add($brand . ' ' . $this->quote($identity->season) . ' ' . $this->quote($identity->modelCode), 'season_model_code', 35);
            }

            if ($identity->description !== null) {
                $add($brand . ' ' . $identity->description, 'description', 30);
            }
        }

        if ($supportsSiteFilter) {
            foreach ($trustedSources as $source) {
                if (($source['is_active'] ?? true) === false || ($source['allow_search'] ?? true) === false) {
                    continue;
                }

                $domain = DomainNormalizer::normalizeDomain($source['domain'] ?? null);

                if ($domain === null) {
                    continue;
                }

                if ($identity->ean !== null) {
                    $add('site:' . $domain . ' ' . $this->quote($identity->ean), 'site_ean', 100, 'image', $domain, ['source' => $source]);
                }

                if ($supplierSku !== null && $identity->colorCode !== null) {
                    $add('site:' . $domain . ' ' . $this->quote($supplierSku) . ' ' . $this->quote($identity->colorCode), 'site_supplier_sku_color_code', 99, 'image', $domain, ['source' => $source]);
                }

                if ($supplierSku !== null && $identity->colorName !== null) {
                    $add('site:' . $domain . ' ' . $this->quote($supplierSku) . ' ' . $this->quote($identity->colorName), 'site_supplier_sku_color_name', 98, 'image', $domain, ['source' => $source]);
                }

                if ($identity->modelCode !== null && $identity->colorCode !== null) {
                    $add('site:' . $domain . ' ' . $this->quote($identity->modelCode) . ' ' . $this->quote($identity->colorCode), 'site_model_code_color_code', 97, 'image', $domain, ['source' => $source]);
                }

                if ($identity->modelCode !== null && $identity->colorName !== null) {
                    $add('site:' . $domain . ' ' . $this->quote($identity->modelCode) . ' ' . $this->quote($identity->colorName), 'site_model_code_color_name', 96, 'image', $domain, ['source' => $source]);
                }

                if ($supplierSku !== null) {
                    $add('site:' . $domain . ' ' . $this->quote($supplierSku), 'site_supplier_sku', 92, 'image', $domain, ['source' => $source]);
                }

                if ($identity->modelCode !== null) {
                    $add('site:' . $domain . ' ' . $this->quote($identity->modelCode), 'site_model_code', 88, 'image', $domain, ['source' => $source]);
                }
            }
        }

        usort($queries, static fn (SearchQueryData $a, SearchQueryData $b): int => $b->weight <=> $a->weight ?: $a->priority <=> $b->priority);

        return array_slice($queries, 0, $maxQueries);
    }

    /**
     * Some ERPs send the supplier SKU as the model code plus a color word ("763435008IW2885 Camel").
     * No page writes that phrase, so its queries only find other products of the brand in that
     * color: the model code queries already search the code. A numeric color code is kept, since
     * shops do write "26SSH01164-002".
     */
    private function supplierSkuAddsOnlyAColorWord(ProductIdentityData $identity): bool
    {
        $supplierSku = TextNormalizer::normalizeText($identity->supplierSku);
        $modelCode = TextNormalizer::normalizeText($identity->modelCode);

        if ($supplierSku === null || $modelCode === null || ! str_starts_with($supplierSku, $modelCode . ' ')) {
            return false;
        }

        $rest = substr($supplierSku, strlen($modelCode) + 1);

        if (preg_match('/\d/', $rest) === 1) {
            return false;
        }

        return in_array($rest, array_filter([
            TextNormalizer::normalizeText($identity->colorCode),
            TextNormalizer::normalizeText($identity->colorName),
        ]), true) || TextNormalizer::mentionedColors($rest) !== [];
    }

    private function quote(?string $value): string
    {
        $value = TextNormalizer::nullableString($value);

        if ($value === null) {
            return '';
        }

        return '"' . str_replace('"', '\"', $value) . '"';
    }
}
