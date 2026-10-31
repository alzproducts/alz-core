<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Mappers;

use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;

/**
 * ProductView derivations shared by the Eloquent assembler and the products list assembler,
 * so both read paths emit the same values.
 */
final class ProductViewDerivations
{
    /**
     * A product with its own stock item uses that item's default supplier, even when it is null:
     * it never falls through to the variations.
     *
     * @param list<ProductVariationView>|null $variations
     */
    public static function defaultSupplier(bool $hasStockItem, ?ProductSupplier $stockItemDefault, ?array $variations): ?ProductSupplier
    {
        if ($hasStockItem) {
            return $stockItemDefault;
        }

        return $variations !== null ? ProductVariationView::commonDefaultSupplier($variations) : null;
    }
}
