<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\ProductViewMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductViewMeta::class)]
final class ProductViewMetaTest extends TestCase
{
    // ========================================================================
    // canEditRrp
    // ========================================================================

    #[Test]
    public function can_edit_rrp_true_when_variations_null(): void
    {
        $meta = new ProductViewMeta(null);

        self::assertTrue($meta->canEditRrp);
    }

    #[Test]
    public function can_edit_rrp_true_when_variations_empty(): void
    {
        $meta = new ProductViewMeta([]);

        self::assertTrue($meta->canEditRrp);
    }

    #[Test]
    public function can_edit_rrp_true_when_single_variation(): void
    {
        $meta = new ProductViewMeta([
            self::createVariation(price: 20.00),
        ]);

        self::assertTrue($meta->canEditRrp);
    }

    #[Test]
    public function can_edit_rrp_true_when_all_variations_have_same_price(): void
    {
        $meta = new ProductViewMeta([
            self::createVariation(price: 20.00),
            self::createVariation(price: 20.00),
            self::createVariation(price: 20.00),
        ]);

        self::assertTrue($meta->canEditRrp);
    }

    #[Test]
    public function can_edit_rrp_false_when_variations_have_different_prices(): void
    {
        $meta = new ProductViewMeta([
            self::createVariation(price: 20.00),
            self::createVariation(price: 25.00),
        ]);

        self::assertFalse($meta->canEditRrp);
    }

    #[Test]
    public function can_edit_rrp_false_when_one_variation_differs(): void
    {
        $meta = new ProductViewMeta([
            self::createVariation(price: 20.00),
            self::createVariation(price: 20.00),
            self::createVariation(price: 21.00),
        ]);

        self::assertFalse($meta->canEditRrp);
    }

    #[Test]
    public function can_edit_rrp_true_regardless_of_sale_prices(): void
    {
        // Same base price, different sale prices — RRP is tied to base price, not effective
        $meta = new ProductViewMeta([
            self::createVariation(price: 20.00, salePrice: 15.00),
            self::createVariation(price: 20.00, salePrice: 12.00),
        ]);

        self::assertTrue($meta->canEditRrp);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private static function createVariation(float $price, ?float $salePrice = null): ProductVariationView
    {
        return new ProductVariationView(
            externalId: 1,
            sku: null,
            gtin: null,
            price: $price,
            costPrice: null,
            salePrice: $salePrice,
            rrp: null,
            effectivePrice: $salePrice !== null && $salePrice > 0 ? $salePrice : $price,
            isOnSale: $salePrice !== null && $salePrice > 0,
            profitMargin: null,
            stock: 10,
            weight: null,
            vatExclusive: false,
            mpn: null,
            imageIndex: null,
            options: [],
        );
    }
}
