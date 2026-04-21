<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Shared\Money\ValueObjects\Money;
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
    | canEditCostPrice
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function can_edit_cost_price_false_when_composite_even_with_supplier(): void
    {
        $view = $this->createView(
            isComposite: true,
            supplierName: 'Acme',
        );

        self::assertFalse($view->canEditCostPrice);
    }

    #[Test]
    public function can_edit_cost_price_true_when_non_composite_with_supplier(): void
    {
        $view = $this->createView(
            isComposite: false,
            supplierName: 'Acme',
        );

        self::assertTrue($view->canEditCostPrice);
    }

    #[Test]
    public function can_edit_cost_price_false_when_non_composite_without_supplier(): void
    {
        $view = $this->createView(isComposite: false);

        self::assertFalse($view->canEditCostPrice);
    }

    #[Test]
    public function can_edit_cost_price_false_when_no_composite_flag_and_no_supplier(): void
    {
        $view = $this->createView();

        self::assertFalse($view->canEditCostPrice);
    }

    /*
    |--------------------------------------------------------------------------
    | commonDefaultSupplier — composite filtering
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function common_default_supplier_skips_composites_and_returns_shared_supplier(): void
    {
        $variations = [
            $this->createView(supplierName: 'Acme', isComposite: false),
            $this->createView(supplierName: 'Acme', isComposite: true),
        ];

        $supplier = ProductVariationView::commonDefaultSupplier($variations);

        self::assertNotNull($supplier);
        self::assertSame('Acme', $supplier->supplierName);
    }

    #[Test]
    public function common_default_supplier_null_when_all_variations_are_composite(): void
    {
        $variations = [
            $this->createView(supplierName: 'Acme', isComposite: true),
            $this->createView(supplierName: 'Acme', isComposite: true),
        ];

        self::assertNull(ProductVariationView::commonDefaultSupplier($variations));
    }

    #[Test]
    public function common_default_supplier_works_normally_when_no_composites(): void
    {
        $variations = [
            $this->createView(supplierName: 'Acme'),
            $this->createView(supplierName: 'Acme'),
        ];

        $supplier = ProductVariationView::commonDefaultSupplier($variations);

        self::assertNotNull($supplier);
        self::assertSame('Acme', $supplier->supplierName);
    }

    #[Test]
    public function common_default_supplier_null_when_non_composite_suppliers_differ(): void
    {
        $variations = [
            $this->createView(supplierName: 'Acme', isComposite: false),
            $this->createView(supplierName: 'Globex', isComposite: false),
            $this->createView(supplierName: 'Acme', isComposite: true),
        ];

        self::assertNull(ProductVariationView::commonDefaultSupplier($variations));
    }

    #[Test]
    public function common_default_supplier_null_when_empty(): void
    {
        self::assertNull(ProductVariationView::commonDefaultSupplier([]));
    }

    /*
    |--------------------------------------------------------------------------
    | commonCostPrice
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function common_cost_price_returns_shared_money_when_all_agree(): void
    {
        $variations = [
            $this->createView(costPrice: 3.50),
            $this->createView(costPrice: 3.50),
        ];

        $result = ProductVariationView::commonCostPrice($variations);

        self::assertNotNull($result);
        self::assertSame(3.50, $result->toNet());
    }

    #[Test]
    public function common_cost_price_returns_null_when_any_differs(): void
    {
        $variations = [
            $this->createView(costPrice: 3.50),
            $this->createView(costPrice: 4.00),
        ];

        self::assertNull(ProductVariationView::commonCostPrice($variations));
    }

    #[Test]
    public function common_cost_price_returns_null_when_any_variation_has_null_cost(): void
    {
        $variations = [
            $this->createView(costPrice: 3.50),
            $this->createView(costPrice: null),
        ];

        self::assertNull(ProductVariationView::commonCostPrice($variations));
    }

    #[Test]
    public function common_cost_price_returns_null_when_first_variation_has_null_cost(): void
    {
        $variations = [
            $this->createView(costPrice: null),
            $this->createView(costPrice: 3.50),
        ];

        self::assertNull(ProductVariationView::commonCostPrice($variations));
    }

    #[Test]
    public function common_cost_price_returns_null_when_empty(): void
    {
        self::assertNull(ProductVariationView::commonCostPrice([]));
    }

    /*
    |--------------------------------------------------------------------------
    | commonPrice
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function common_price_returns_shared_money_when_all_agree(): void
    {
        $variations = [
            $this->createView(price: 29.99),
            $this->createView(price: 29.99),
        ];

        $result = ProductVariationView::commonPrice($variations);

        self::assertNotNull($result);
        self::assertSame(29.99, $result->toGross());
    }

    #[Test]
    public function common_price_returns_null_when_any_differs(): void
    {
        $variations = [
            $this->createView(price: 29.99),
            $this->createView(price: 39.99),
        ];

        self::assertNull(ProductVariationView::commonPrice($variations));
    }

    #[Test]
    public function common_price_returns_null_when_empty(): void
    {
        self::assertNull(ProductVariationView::commonPrice([]));
    }

    /*
    |--------------------------------------------------------------------------
    | commonEffectivePrice
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function common_effective_price_returns_shared_money_when_all_agree(): void
    {
        $variations = [
            $this->createView(effectivePrice: 12.50),
            $this->createView(effectivePrice: 12.50),
        ];

        $result = ProductVariationView::commonEffectivePrice($variations);

        self::assertNotNull($result);
        self::assertSame(12.50, $result->toGross());
    }

    #[Test]
    public function common_effective_price_returns_null_when_any_differs(): void
    {
        $variations = [
            $this->createView(effectivePrice: 12.50),
            $this->createView(effectivePrice: 14.00),
        ];

        self::assertNull(ProductVariationView::commonEffectivePrice($variations));
    }

    #[Test]
    public function common_effective_price_returns_null_when_empty(): void
    {
        self::assertNull(ProductVariationView::commonEffectivePrice([]));
    }

    /*
    |--------------------------------------------------------------------------
    | minPrice / minEffectivePrice
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function min_price_picks_lowest(): void
    {
        $variations = [
            $this->createView(price: 30.00),
            $this->createView(price: 10.00),
            $this->createView(price: 20.00),
        ];

        $result = ProductVariationView::minPrice($variations);

        self::assertInstanceOf(Money::class, $result);
        self::assertSame(10.00, $result->toGross());
    }

    #[Test]
    public function min_price_returns_null_when_empty(): void
    {
        self::assertNull(ProductVariationView::minPrice([]));
    }

    #[Test]
    public function min_effective_price_picks_lowest(): void
    {
        $variations = [
            $this->createView(effectivePrice: 25.00),
            $this->createView(effectivePrice: 9.99),
            $this->createView(effectivePrice: 18.00),
        ];

        $result = ProductVariationView::minEffectivePrice($variations);

        self::assertInstanceOf(Money::class, $result);
        self::assertSame(9.99, $result->toGross());
    }

    #[Test]
    public function min_effective_price_returns_null_when_empty(): void
    {
        self::assertNull(ProductVariationView::minEffectivePrice([]));
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
        bool $isComposite = false,
        ?string $supplierName = null,
    ): ProductVariationView {
        return new ProductVariationView(
            externalId: 1,
            sku: $sku,
            gtin: null,
            price: $price,
            costPrice: $costPrice,
            salePrice: $salePrice,
            rrp: null,
            effectivePrice: $effectivePrice,
            isOnSale: $isOnSale,
            profitMargin: $profitMargin,
            availableStock: 10,
            physicalStock: 10,
            weight: $weight,
            vatExclusive: false,
            mpn: null,
            imageIndex: null,
            options: [],
            defaultSupplier: $supplierName !== null ? self::createSupplier($supplierName) : null,
            isComposite: $isComposite,
        );
    }

    private static function createSupplier(string $name): ProductSupplier
    {
        return new ProductSupplier(
            supplierName: $name,
            purchasePrice: null,
            isDefault: true,
        );
    }
}
