<?php

declare(strict_types=1);

namespace App\Application\Shopwired\SaleManagement\Resolvers;

use App\Application\Shopwired\SaleManagement\Results\ProductSaleStateResult;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\ValueObjects\IntId;

/**
 * Pure calculation: given a Product, determines what sale state corrections are needed.
 *
 * "On sale" means any SKU (master or variant) has an active sale price,
 * defined by Product::isSaleActive(). Only checks price ↔ category alignment —
 * custom field presence is not evaluated (matches simplified drift query).
 */
final readonly class ProductSaleStateResolver
{
    public function __construct(
        private int $saleCategoryId,
    ) {}

    public function evaluate(Product $product): ProductSaleStateResult
    {
        $shouldBeOnSale = $product->hasAnySaleActive();
        $isInSaleCategory = $product->isInCategory($this->saleCategoryId);

        return new ProductSaleStateResult(
            productId: IntId::fromTrusted($product->id),
            shouldBeOnSale: $shouldBeOnSale,
            needsAddToSale: $shouldBeOnSale && ! $isInSaleCategory,
            needsRemoveFromSale: ! $shouldBeOnSale && $isInSaleCategory,
        );
    }
}
