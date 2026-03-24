<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\ResolvedVariationPrices;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webmozart\Assert\InvalidArgumentException;

/**
 * Tests for ResolvedVariationPrices value object.
 */
#[CoversClass(ResolvedVariationPrices::class)]
final class ResolvedVariationPricesTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction & Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_with_valid_prices(): void
    {
        $prices = new ResolvedVariationPrices(
            price: 29.99,
            costPrice: 15.00,
            salePrice: 24.99,
        );

        self::assertSame(29.99, $prices->price);
        self::assertSame(15.00, $prices->costPrice);
        self::assertSame(24.99, $prices->salePrice);
    }

    #[Test]
    public function it_allows_null_cost_price(): void
    {
        $prices = new ResolvedVariationPrices(
            price: 29.99,
            costPrice: null,
            salePrice: null,
        );

        self::assertNull($prices->costPrice);
    }

    #[Test]
    public function it_allows_null_sale_price(): void
    {
        $prices = new ResolvedVariationPrices(
            price: 29.99,
            costPrice: 15.00,
            salePrice: null,
        );

        self::assertNull($prices->salePrice);
    }

    #[Test]
    public function it_allows_zero_price(): void
    {
        $prices = new ResolvedVariationPrices(
            price: 0.0,
            costPrice: null,
            salePrice: null,
        );

        self::assertSame(0.0, $prices->price);
    }

    #[Test]
    public function it_allows_zero_sale_price(): void
    {
        $prices = new ResolvedVariationPrices(
            price: 29.99,
            costPrice: null,
            salePrice: 0.0,
        );

        self::assertSame(0.0, $prices->salePrice);
    }

    #[Test]
    public function it_rejects_negative_price(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Price cannot be negative');

        new ResolvedVariationPrices(
            price: -0.01,
            costPrice: null,
            salePrice: null,
        );
    }

    #[Test]
    public function it_rejects_zero_cost_price(): void
    {
        // Cost price must be > 0 if set (0 is not a valid cost)
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cost price must be greater than 0');

        new ResolvedVariationPrices(
            price: 29.99,
            costPrice: 0.0,
            salePrice: null,
        );
    }

    #[Test]
    public function it_rejects_negative_cost_price(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cost price must be greater than 0');

        new ResolvedVariationPrices(
            price: 29.99,
            costPrice: -5.00,
            salePrice: null,
        );
    }

    #[Test]
    public function it_rejects_negative_sale_price(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sale price cannot be negative');

        new ResolvedVariationPrices(
            price: 29.99,
            costPrice: null,
            salePrice: -1.00,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | isOnSale() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function is_on_sale_returns_true_when_sale_price_less_than_price(): void
    {
        $prices = new ResolvedVariationPrices(
            price: 29.99,
            costPrice: null,
            salePrice: 19.99,
        );

        self::assertTrue($prices->isOnSale());
    }

    #[Test]
    public function is_on_sale_returns_false_when_sale_price_is_null(): void
    {
        $prices = new ResolvedVariationPrices(
            price: 29.99,
            costPrice: null,
            salePrice: null,
        );

        self::assertFalse($prices->isOnSale());
    }

    #[Test]
    public function is_on_sale_returns_false_when_sale_price_equals_price(): void
    {
        $prices = new ResolvedVariationPrices(
            price: 29.99,
            costPrice: null,
            salePrice: 29.99,
        );

        self::assertFalse($prices->isOnSale());
    }

    #[Test]
    public function is_on_sale_returns_false_when_sale_price_greater_than_price(): void
    {
        // Edge case: sale price higher than regular (invalid but shouldn't crash)
        $prices = new ResolvedVariationPrices(
            price: 19.99,
            costPrice: null,
            salePrice: 29.99,
        );

        self::assertFalse($prices->isOnSale());
    }

    /*
    |--------------------------------------------------------------------------
    | effectivePrice() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function effective_price_returns_sale_price_when_on_sale(): void
    {
        $prices = new ResolvedVariationPrices(
            price: 29.99,
            costPrice: null,
            salePrice: 19.99,
        );

        self::assertSame(19.99, $prices->effectivePrice());
    }

    #[Test]
    public function effective_price_returns_regular_price_when_not_on_sale(): void
    {
        $prices = new ResolvedVariationPrices(
            price: 29.99,
            costPrice: null,
            salePrice: null,
        );

        self::assertSame(29.99, $prices->effectivePrice());
    }

    #[Test]
    public function effective_price_returns_regular_price_when_sale_price_higher(): void
    {
        $prices = new ResolvedVariationPrices(
            price: 19.99,
            costPrice: null,
            salePrice: 29.99,
        );

        self::assertSame(19.99, $prices->effectivePrice());
    }

    #[Test]
    public function effective_price_returns_regular_price_when_sale_price_is_zero(): void
    {
        // salePrice = 0 means "no sale" in ShopWired, not "free item"
        $prices = new ResolvedVariationPrices(
            price: 29.99,
            costPrice: null,
            salePrice: 0.0,
        );

        self::assertSame(29.99, $prices->effectivePrice());
        self::assertFalse($prices->isOnSale());
    }

    /*
    |--------------------------------------------------------------------------
    | marginPercent() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function margin_percent_returns_null_when_cost_price_is_null(): void
    {
        $prices = new ResolvedVariationPrices(
            price: 29.99,
            costPrice: null,
            salePrice: null,
        );

        self::assertNull($prices->marginPercent());
    }

    #[Test]
    public function margin_percent_calculates_correctly(): void
    {
        // Effective price = 30.00, cost = 15.00
        // Margin = (30 - 15) / 15 * 100 = 100%
        $prices = new ResolvedVariationPrices(
            price: 30.00,
            costPrice: 15.00,
            salePrice: null,
        );

        self::assertSame(100.0, $prices->marginPercent());
    }

    #[Test]
    public function margin_percent_uses_sale_price_when_on_sale(): void
    {
        // Effective price = 20.00 (sale), cost = 10.00
        // Margin = (20 - 10) / 10 * 100 = 100%
        $prices = new ResolvedVariationPrices(
            price: 30.00,
            costPrice: 10.00,
            salePrice: 20.00,
        );

        self::assertSame(100.0, $prices->marginPercent());
    }

    #[Test]
    public function margin_percent_handles_50_percent_margin(): void
    {
        // Effective price = 30.00, cost = 20.00
        // Margin = (30 - 20) / 20 * 100 = 50%
        $prices = new ResolvedVariationPrices(
            price: 30.00,
            costPrice: 20.00,
            salePrice: null,
        );

        self::assertSame(50.0, $prices->marginPercent());
    }

    #[Test]
    public function margin_percent_handles_negative_margin(): void
    {
        // Selling below cost
        // Effective price = 10.00, cost = 15.00
        // Margin = (10 - 15) / 15 * 100 = -33.33...%
        $prices = new ResolvedVariationPrices(
            price: 10.00,
            costPrice: 15.00,
            salePrice: null,
        );

        self::assertEqualsWithDelta(-33.33, $prices->marginPercent(), 0.01);
    }
}
