<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\TaxType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductRetailPricing::class)]
final class ProductRetailPricingTest extends TestCase
{
    // ========================================================================
    // saleActive()
    // ========================================================================

    #[Test]
    public function sale_active_returns_false_when_sale_price_is_null(): void
    {
        $pricing = new ProductRetailPricing(
            basePrice: Money::inclusive(20.00),
            salePrice: null,
        );

        self::assertFalse($pricing->saleActive());
    }

    #[Test]
    public function sale_active_returns_false_when_sale_price_is_zero(): void
    {
        $pricing = new ProductRetailPricing(
            basePrice: Money::inclusive(20.00),
            salePrice: Money::inclusive(0.00),
        );

        self::assertFalse($pricing->saleActive());
    }

    #[Test]
    public function sale_active_returns_true_when_sale_price_is_positive(): void
    {
        $pricing = new ProductRetailPricing(
            basePrice: Money::inclusive(20.00),
            salePrice: Money::inclusive(15.00),
        );

        self::assertTrue($pricing->saleActive());
    }

    // ========================================================================
    // effectivePrice()
    // ========================================================================

    #[Test]
    public function effective_price_returns_base_when_no_sale(): void
    {
        $pricing = new ProductRetailPricing(
            basePrice: Money::inclusive(20.00),
        );

        self::assertSame(20.0, $pricing->effectivePrice()->toGross());
    }

    #[Test]
    public function effective_price_returns_base_when_sale_is_zero(): void
    {
        $pricing = new ProductRetailPricing(
            basePrice: Money::inclusive(20.00),
            salePrice: Money::inclusive(0.00),
        );

        self::assertSame(20.0, $pricing->effectivePrice()->toGross());
    }

    #[Test]
    public function effective_price_returns_sale_when_sale_is_active(): void
    {
        $pricing = new ProductRetailPricing(
            basePrice: Money::inclusive(20.00),
            salePrice: Money::inclusive(15.00),
        );

        self::assertSame(15.0, $pricing->effectivePrice()->toGross());
    }

    // ========================================================================
    // forMainProduct()
    // ========================================================================

    #[Test]
    public function for_main_product_without_sale(): void
    {
        $pricing = ProductRetailPricing::forMainProduct(29.99, null);

        self::assertSame(29.99, $pricing->basePrice->toGross());
        self::assertNull($pricing->salePrice);
        self::assertFalse($pricing->saleActive());
        self::assertSame(29.99, $pricing->effectivePrice()->toGross());
    }

    #[Test]
    public function for_main_product_with_sale(): void
    {
        $pricing = ProductRetailPricing::forMainProduct(29.99, 19.99);

        self::assertSame(29.99, $pricing->basePrice->toGross());
        self::assertNotNull($pricing->salePrice);
        self::assertSame(19.99, $pricing->salePrice->toGross());
        self::assertTrue($pricing->saleActive());
        self::assertSame(19.99, $pricing->effectivePrice()->toGross());
    }

    // ========================================================================
    // forVariation()
    // ========================================================================

    #[Test]
    public function for_variation_uses_own_price_when_set(): void
    {
        $pricing = ProductRetailPricing::forVariation(
            variationPrice: 25.00,
            salePrice: null,
            parentPrice: 20.00,
        );

        self::assertSame(25.0, $pricing->basePrice->toGross());
    }

    #[Test]
    public function for_variation_inherits_parent_price_when_null(): void
    {
        $pricing = ProductRetailPricing::forVariation(
            variationPrice: null,
            salePrice: null,
            parentPrice: 20.00,
        );

        self::assertSame(20.0, $pricing->basePrice->toGross());
    }

    #[Test]
    public function for_variation_with_sale_price(): void
    {
        $pricing = ProductRetailPricing::forVariation(
            variationPrice: 25.00,
            salePrice: 18.00,
            parentPrice: 20.00,
        );

        self::assertSame(25.0, $pricing->basePrice->toGross());
        self::assertNotNull($pricing->salePrice);
        self::assertSame(18.0, $pricing->salePrice->toGross());
        self::assertTrue($pricing->saleActive());
    }

    // ========================================================================
    // taxType()
    // ========================================================================

    #[Test]
    public function tax_type_returns_base_price_tax_type(): void
    {
        $pricing = new ProductRetailPricing(
            basePrice: Money::inclusive(20.00),
        );

        self::assertSame(TaxType::Inclusive, $pricing->taxType());
    }

    #[Test]
    public function tax_type_for_zero_rated_product(): void
    {
        $pricing = new ProductRetailPricing(
            basePrice: Money::zeroRated(20.00),
        );

        self::assertSame(TaxType::ZeroRated, $pricing->taxType());
        self::assertFalse($pricing->taxType()->hasTax());
    }
}
