<?php

declare(strict_types=1);

namespace App\Application\Shopwired\SaleManagement\Resolvers;

use App\Application\Shopwired\SaleManagement\Results\ProductSaleStateResult;
use App\Application\Shopwired\SaleManagement\Results\SkuSaleStateResult;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\ValueObjects\IntId;

/**
 * Pure calculation: given a Product, determines what sale state corrections are needed.
 *
 * "On sale" means: salePrice is not null, greater than zero, and less than price.
 * This matches the getProductsOnSale() query semantics (sale_price > 0), NOT
 * BasicProductTrait::isOnSale() which returns true for salePrice=0.00 (used for removal).
 */
final readonly class ProductSaleStateResolver
{
    public function __construct(
        private int $saleCategoryId,
    ) {}

    public function evaluate(Product $product): ProductSaleStateResult
    {
        $shouldBeOnSale = self::shouldBeOnSale($product);
        $isInSaleCategory = $product->isInCategory($this->saleCategoryId);
        $hasSaleCustomFields = $product->hasAnySaleCustomField();

        $needsAddToSale = $shouldBeOnSale && (! $isInSaleCategory || ! $hasSaleCustomFields);
        $needsRemoveFromSale = ! $shouldBeOnSale && ($isInSaleCategory || $hasSaleCustomFields);

        $skuSaleStates = \array_map(
            static fn(Sku $sku): SkuSaleStateResult => new SkuSaleStateResult(
                sku: $sku,
                shouldBeInSale: $shouldBeOnSale,
            ),
            $product->allSkus(),
        );

        return new ProductSaleStateResult(
            productId: IntId::fromTrusted($product->id),
            shouldBeOnSale: $shouldBeOnSale,
            needsAddToSale: $needsAddToSale,
            needsRemoveFromSale: $needsRemoveFromSale,
            skuSaleStates: $skuSaleStates,
        );
    }

    private static function shouldBeOnSale(Product $product): bool
    {
        return $product->isOnSale();
    }

}
