<?php

declare(strict_types=1);

namespace Padosoft\ProductImageDiscovery\DTO;

use JsonSerializable;

final readonly class SearchQueryData implements JsonSerializable
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $query,
        public string $intent,
        public int $priority,
        public int $weight = 0,
        public string $type = 'image',
        public ?string $siteDomain = null,
        public array $metadata = [],
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            query: (string) ($attributes['query'] ?? ''),
            intent: (string) ($attributes['intent'] ?? ''),
            priority: (int) ($attributes['priority'] ?? 0),
            weight: (int) ($attributes['weight'] ?? 0),
            type: (string) ($attributes['type'] ?? 'image'),
            siteDomain: is_string($attributes['site_domain'] ?? null) ? $attributes['site_domain'] : null,
            metadata: is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [],
        );
    }

    /**
     * The identifier the query searches by: ean, supplier_sku, model_code or description. Site
     * queries belong to the family of the identifier they carry; other intents are their own family.
     */
    public function family(): string
    {
        $intent = str_starts_with($this->intent, 'site_') ? substr($this->intent, 5) : $this->intent;

        return match (true) {
            str_starts_with($intent, 'ean') => 'ean',
            str_starts_with($intent, 'supplier_sku') => 'supplier_sku',
            str_starts_with($intent, 'model_code'), $intent === 'season_model_code' => 'model_code',
            str_starts_with($intent, 'description') => 'description',
            default => $intent,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'intent' => $this->intent,
            'priority' => $this->priority,
            'weight' => $this->weight,
            'type' => $this->type,
            'site_domain' => $this->siteDomain,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
