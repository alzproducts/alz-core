<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Inventory\ValueObjects\Weight;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductVariationView::class)]
final class ProductVariationViewTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Profit Margin
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function profit_margin_calculated_from_price_and_cost_price(): void
    {
        $view = $this->createView(
            price: Money::inclusive(100.00),
            costPrice: Money::inclusive(60.00),
        );

        // (100 - 60) / 100 × 100 = 40%
        self::assertSame(40.0, $view->profitMargin);
    }

    #[Test]
    public function profit_margin_null_when_cost_price_null(): void
    {
        $view = $this->createView(
            price: Money::inclusive(100.00),
            costPrice: null,
        );

        self::assertNull($view->profitMargin);
    }

    #[Test]
    public function profit_margin_null_when_price_is_zero(): void
    {
        $view = $this->createView(
            price: Money::inclusive(0.0),
            costPrice: Money::inclusive(10.00),
        );

        self::assertNull($view->profitMargin);
    }

    #[Test]
    public function profit_margin_negative_when_cost_exceeds_price(): void
    {
        $view = $this->createView(
            price: Money::inclusive(50.00),
            costPrice: Money::inclusive(75.00),
        );

        // (50 - 75) / 50 × 100 = -50%
        self::assertSame(-50.0, $view->profitMargin);
    }

    #[Test]
    public function profit_margin_rounds_to_two_decimals(): void
    {
        $view = $this->createView(
            price: Money::inclusive(30.00),
            costPrice: Money::inclusive(19.99),
        );

        // (30 - 19.99) / 30 × 100 = 33.366...%
        self::assertSame(33.37, $view->profitMargin);
    }

    /*
    |--------------------------------------------------------------------------
    | isOnSale
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function is_on_sale_true_when_sale_price_less_than_price(): void
    {
        $view = $this->createView(
            price: Money::inclusive(100.00),
            salePrice: Money::inclusive(75.00),
        );

        self::assertTrue($view->isOnSale);
    }

    #[Test]
    public function is_on_sale_false_when_no_sale_price(): void
    {
        $view = $this->createView(
            price: Money::inclusive(100.00),
            salePrice: null,
        );

        self::assertFalse($view->isOnSale);
    }

    #[Test]
    public function is_on_sale_false_when_sale_price_equals_price(): void
    {
        $view = $this->createView(
            price: Money::inclusive(100.00),
            salePrice: Money::inclusive(100.00),
        );

        self::assertFalse($view->isOnSale);
    }

    #[Test]
    public function is_on_sale_false_when_sale_price_is_zero(): void
    {
        $view = $this->createView(
            price: Money::inclusive(100.00),
            salePrice: Money::inclusive(0.0),
        );

        self::assertFalse($view->isOnSale);
    }

    /*
    |--------------------------------------------------------------------------
    | Domain Types
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function weight_stored_as_weight_vo(): void
    {
        $view = $this->createView(weight: Weight::kilogram(2.5));

        self::assertSame(2.5, $view->weight?->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function createView(
        ?Money $price = null,
        ?Money $costPrice = null,
        ?Money $salePrice = null,
        ?Weight $weight = null,
    ): ProductVariationView {
        return new ProductVariationView(
            id: IntId::from(1),
            sku: null,
            gtin: null,
            price: $price ?? Money::inclusive(100.00),
            costPrice: $costPrice,
            salePrice: $salePrice,
            stock: 10,
            weight: $weight,
            mpn: null,
            imageIndex: null,
            options: [],
        );
    }
}
