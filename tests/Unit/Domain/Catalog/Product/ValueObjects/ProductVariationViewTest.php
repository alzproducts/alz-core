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
    | Constructor passthrough
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function is_on_sale_reflects_constructor_value(): void
    {
        $view = $this->createView(isOnSale: true);

        self::assertTrue($view->isOnSale);
    }

    #[Test]
    public function is_on_sale_false_by_default(): void
    {
        $view = $this->createView(isOnSale: false);

        self::assertFalse($view->isOnSale);
    }

    #[Test]
    public function profit_margin_reflects_constructor_value(): void
    {
        $view = $this->createView(profitMargin: 42.5);

        self::assertSame(42.5, $view->profitMargin);
    }

    #[Test]
    public function profit_margin_null_when_passed_null(): void
    {
        $view = $this->createView(profitMargin: null);

        self::assertNull($view->profitMargin);
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
        bool $isOnSale = false,
        ?float $profitMargin = null,
        ?Weight $weight = null,
    ): ProductVariationView {
        return new ProductVariationView(
            id: IntId::from(1),
            sku: null,
            gtin: null,
            price: $price ?? Money::inclusive(100.00),
            costPrice: $costPrice,
            salePrice: $salePrice,
            isOnSale: $isOnSale,
            profitMargin: $profitMargin,
            stock: 10,
            weight: $weight,
            mpn: null,
            imageIndex: null,
            options: [],
        );
    }
}
