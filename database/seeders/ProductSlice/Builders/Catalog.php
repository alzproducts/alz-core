<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

final readonly class Catalog
{
    /**
     * @param list<array<string, mixed>> $productRows
     * @param list<array<string, mixed>> $variationRows
     * @param list<CatalogSku> $skus every product and variation SKU
     * @param list<CatalogSku> $stockedSkus the SKUs a stock item matches
     */
    public function __construct(
        public array $productRows,
        public array $variationRows,
        public array $skus,
        public array $stockedSkus,
    ) {}
}
