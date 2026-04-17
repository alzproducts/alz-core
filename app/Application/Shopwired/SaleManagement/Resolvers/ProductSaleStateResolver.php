<?php

declare(strict_types=1);

namespace App\Application\Shopwired\SaleManagement\Resolvers;

use App\Application\Shopwired\SaleManagement\Results\ProductSaleStateResult;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\ValueObjects\IntId;

/**
 * Pure calculation: given a ProductView, determines what sale state corrections are needed.
 *
 * "On sale" is expressed by `ProductView::$hasAnySale` (true when the master product or
 * any variation has an active sale price). Only checks price ↔ category alignment —
 * custom field presence is not evaluated (matches simplified drift query).
 */
final readonly class ProductSaleStateResolver
{
    public function __construct(
        private int $saleCategoryId,
    ) {}

    public function evaluate(ProductView $view): ProductSaleStateResult
    {
        $shouldBeOnSale = $view->hasAnySale;
        $isInSaleCategory = $view->isInCategory(IntId::from($this->saleCategoryId));

        return new ProductSaleStateResult(
            productId: $view->id,
            shouldBeOnSale: $shouldBeOnSale,
            needsAddToSale: $shouldBeOnSale && ! $isInSaleCategory,
            needsRemoveFromSale: ! $shouldBeOnSale && $isInSaleCategory,
        );
    }
}
