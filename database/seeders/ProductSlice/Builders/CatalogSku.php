<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

/**
 * A live catalog SKU (trimmed) and the product, or variation, that carries it.
 */
final readonly class CatalogSku
{
    public function __construct(
        public string $sku,
        public int $parentExternalId,
        public ?int $variationExternalId,
        public string $title,
        public bool $isActive,
    ) {}
}
