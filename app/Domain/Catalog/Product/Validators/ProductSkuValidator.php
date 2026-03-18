<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Validators;

use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\Sku;

/**
 * Validates SKU ownership against a product.
 *
 * Pure domain validation — verifies that given SKUs belong to a product
 * (either as the master SKU or one of its variation SKUs).
 */
final readonly class ProductSkuValidator
{
    /**
     * Find SKUs that don't belong to the product.
     *
     * @param list<Sku> $skus SKUs to check
     *
     * @return list<Sku> SKUs not found on this product
     */
    public static function findUnownedSkus(Product $product, array $skus): array
    {
        $owned = [];
        foreach ($product->allSkus() as $sku) {
            $owned[$sku->value] = true;
        }

        return \array_values(\array_filter(
            $skus,
            static fn(Sku $sku): bool => ! isset($owned[$sku->value]),
        ));
    }
}
