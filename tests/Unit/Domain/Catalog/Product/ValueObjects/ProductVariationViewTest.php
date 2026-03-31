<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
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
    | Self-construction from primitives
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function self_constructs_id_from_int(): void
    {
        $view = $this->createView();

        self::assertSame(1, $view->id->value);
    }

    #[Test]
    public function self_constructs_price_as_money(): void
    {
        $view = $this->createView(price: 50.00);

        self::assertSame(50.0, $view->price->toGross());
    }

    #[Test]
    public function self_constructs_effective_price_as_money(): void
    {
        $view = $this->createView(effectivePrice: 39.99);

        self::assertSame(39.99, $view->effectivePrice->toGross());
    }

    #[Test]
    public function weight_stored_as_weight_vo(): void
    {
        $view = $this->createView(weight: 2.5);

        self::assertSame(2.5, $view->weight?->value);
    }

    #[Test]
    public function weight_null_when_not_provided(): void
    {
        $view = $this->createView(weight: null);

        self::assertNull($view->weight);
    }

    #[Test]
    public function sku_constructed_from_string(): void
    {
        $view = $this->createView(sku: 'VAR-001');

        self::assertSame('VAR-001', $view->sku?->value);
    }

    #[Test]
    public function sku_null_for_empty_string(): void
    {
        $view = $this->createView(sku: '');

        self::assertNull($view->sku);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function createView(
        float $price = 100.00,
        ?float $costPrice = null,
        ?float $salePrice = null,
        float $effectivePrice = 100.00,
        bool $isOnSale = false,
        ?float $profitMargin = null,
        ?float $weight = null,
        ?string $sku = null,
    ): ProductVariationView {
        return new ProductVariationView(
            externalId: 1,
            sku: $sku,
            gtin: null,
            price: $price,
            costPrice: $costPrice,
            salePrice: $salePrice,
            effectivePrice: $effectivePrice,
            isOnSale: $isOnSale,
            profitMargin: $profitMargin,
            stock: 10,
            weight: $weight,
            vatExclusive: false,
            mpn: null,
            imageIndex: null,
            options: [],
        );
    }
}
