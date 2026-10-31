<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

final class CatalogCollector
{
    /** @var list<array<string, mixed>> */
    private array $productRows = [];

    /** @var list<array<string, mixed>> */
    private array $variationRows = [];

    /** @var list<CatalogSku> */
    private array $skus = [];

    /** @var list<CatalogSku> */
    private array $stockedSkus = [];

    /**
     * @param array<string, mixed> $row
     * @param array{CatalogSku, bool}|null $sku the SKU and whether a stock item matches it
     */
    public function addProduct(array $row, ?array $sku): void
    {
        $this->productRows[] = $row;
        $this->addSku($sku);
    }

    /**
     * @param array<string, mixed> $row
     * @param array{CatalogSku, bool}|null $sku the SKU and whether a stock item matches it
     */
    public function addVariation(array $row, ?array $sku): void
    {
        $this->variationRows[] = $row;
        $this->addSku($sku);
    }

    public function catalog(): Catalog
    {
        return new Catalog($this->productRows, $this->variationRows, $this->skus, $this->stockedSkus);
    }

    /**
     * @param array{CatalogSku, bool}|null $sku
     */
    private function addSku(?array $sku): void
    {
        if ($sku === null) {
            return;
        }

        [$catalogSku, $stocked] = $sku;
        $this->skus[] = $catalogSku;

        if ($stocked) {
            $this->stockedSkus[] = $catalogSku;
        }
    }
}
