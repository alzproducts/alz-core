<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Transformers;

use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;

/**
 * Transforms a Product VO into a SKU-keyed retail pricing map.
 *
 * Extracts base and sale prices from the master product and all variations,
 * converting raw floats into Money-backed ProductRetailPricing snapshots.
 */
final readonly class ProductRetailPricingTransformer
{
    /**
     * Build a pricing map from a Product VO (master + all variations).
     *
     * @return array<string, ProductRetailPricing> Keyed by SKU value
     */
    public static function fromProduct(Product $product): array
    {
        $map = [];

        if ($product->sku !== null && $product->sku !== '') {
            $map[$product->sku] = ProductRetailPricing::forMainProduct($product->price, $product->salePrice);
        }

        foreach ($product->variations ?? [] as $variation) {
            if ($variation->sku === null || $variation->sku === '') {
                continue;
            }

            $map[$variation->sku] = ProductRetailPricing::forVariation(
                $variation->price,
                $variation->salePrice,
                $product->price,
            );
        }

        return $map;
    }
}
