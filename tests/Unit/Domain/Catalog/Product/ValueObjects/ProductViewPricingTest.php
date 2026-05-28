<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\MasterPricing;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\ProductViewPricing;
use App\Domain\Shared\Money\ValueObjects\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductViewPricing::class)]
final class ProductViewPricingTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Constructor
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function constructor_stores_all_properties(): void
    {
        $price = Money::inclusive(20.00);
        $effective = Money::inclusive(18.00);
        $cost = Money::exclusive(5.00);

        $pricing = new ProductViewPricing(
            price: $price,
            effectivePrice: $effective,
            costPrice: $cost,
            profitMargin: 67.5,
        );

        self::assertSame($price, $pricing->price);
        self::assertSame($effective, $pricing->effectivePrice);
        self::assertSame($cost, $pricing->costPrice);
        self::assertSame(67.5, $pricing->profitMargin);
    }

    #[Test]
    public function constructor_accepts_null_cost_and_margin(): void
    {
        $pricing = new ProductViewPricing(
            price: Money::inclusive(20.00),
            effectivePrice: Money::inclusive(20.00),
            costPrice: null,
            profitMargin: null,
        );

        self::assertNull($pricing->costPrice);
        self::assertNull($pricing->profitMargin);
    }

    /*
    |--------------------------------------------------------------------------
    | aggregate — no variations
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function aggregate_returns_master_values_when_variations_null(): void
    {
        $master = new MasterPricing(
            price: Money::inclusive(50.00),
            effectivePrice: Money::inclusive(45.00),
            costPrice: Money::exclusive(10.00),
            profitMargin: 73.33,
        );

        $pricing = ProductViewPricing::aggregate($master, null);

        self::assertSame(50.00, $pricing->price->toGross());
        self::assertSame(45.00, $pricing->effectivePrice->toGross());
        self::assertSame(10.00, $pricing->costPrice?->toNet());
        self::assertSame(73.33, $pricing->profitMargin);
    }

    #[Test]
    public function aggregate_treats_empty_variations_array_as_null(): void
    {
        $master = new MasterPricing(
            price: Money::inclusive(50.00),
            effectivePrice: Money::inclusive(45.00),
            costPrice: Money::exclusive(10.00),
            profitMargin: 73.33,
        );

        $pricing = ProductViewPricing::aggregate($master, []);

        self::assertSame(50.00, $pricing->price->toGross());
        self::assertSame(45.00, $pricing->effectivePrice->toGross());
        self::assertSame(10.00, $pricing->costPrice?->toNet());
        self::assertSame(73.33, $pricing->profitMargin);
    }

    #[Test]
    public function aggregate_keeps_master_price_when_non_zero_even_with_variations(): void
    {
        $master = new MasterPricing(
            price: Money::inclusive(99.99),
            effectivePrice: Money::inclusive(99.99),
            costPrice: Money::exclusive(50.00),
            profitMargin: 40.0,
        );
        $variations = [$this->variation(price: 10.00, effectivePrice: 10.00)];

        $pricing = ProductViewPricing::aggregate($master, $variations);

        self::assertSame(99.99, $pricing->price->toGross());
        self::assertSame(99.99, $pricing->effectivePrice->toGross());
    }

    /*
    |--------------------------------------------------------------------------
    | aggregate — zero master price falls back to variation price
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function aggregate_uses_common_variation_price_when_master_price_zero(): void
    {
        $master = new MasterPricing(
            price: Money::inclusive(0.0),
            effectivePrice: Money::inclusive(15.00),
            costPrice: Money::exclusive(5.00),
            profitMargin: 50.0,
        );
        $variations = [
            $this->variation(price: 25.00, effectivePrice: 15.00),
            $this->variation(price: 25.00, effectivePrice: 15.00),
        ];

        $pricing = ProductViewPricing::aggregate($master, $variations);

        self::assertSame(25.00, $pricing->price->toGross());
    }

    #[Test]
    public function aggregate_falls_back_to_min_variation_price_when_no_common_price(): void
    {
        $master = new MasterPricing(
            price: Money::inclusive(0.0),
            effectivePrice: Money::inclusive(20.00),
            costPrice: null,
            profitMargin: null,
        );
        $variations = [
            $this->variation(price: 30.00, effectivePrice: 20.00),
            $this->variation(price: 10.00, effectivePrice: 20.00),
            $this->variation(price: 20.00, effectivePrice: 20.00),
        ];

        $pricing = ProductViewPricing::aggregate($master, $variations);

        self::assertSame(10.00, $pricing->price->toGross());
    }

    /*
    |--------------------------------------------------------------------------
    | aggregate — effective price fallback
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function aggregate_uses_common_effective_price_when_master_effective_zero(): void
    {
        $master = new MasterPricing(
            price: Money::inclusive(20.00),
            effectivePrice: Money::inclusive(0.0),
            costPrice: Money::exclusive(5.00),
            profitMargin: 50.0,
        );
        $variations = [
            $this->variation(price: 20.00, effectivePrice: 12.50),
            $this->variation(price: 20.00, effectivePrice: 12.50),
        ];

        $pricing = ProductViewPricing::aggregate($master, $variations);

        self::assertSame(12.50, $pricing->effectivePrice->toGross());
    }

    #[Test]
    public function aggregate_falls_back_to_min_effective_when_master_effective_zero_and_no_common(): void
    {
        $master = new MasterPricing(
            price: Money::inclusive(20.00),
            effectivePrice: Money::inclusive(0.0),
            costPrice: null,
            profitMargin: null,
        );
        $variations = [
            $this->variation(price: 20.00, effectivePrice: 18.00),
            $this->variation(price: 20.00, effectivePrice: 9.99),
        ];

        $pricing = ProductViewPricing::aggregate($master, $variations);

        self::assertSame(9.99, $pricing->effectivePrice->toGross());
    }

    #[Test]
    public function aggregate_keeps_master_effective_when_zero_and_variations_have_zero_effective(): void
    {
        $master = new MasterPricing(
            price: Money::inclusive(20.00),
            effectivePrice: Money::inclusive(0.0),
            costPrice: null,
            profitMargin: null,
        );
        $variations = [
            $this->variation(price: 20.00, effectivePrice: 0.0),
        ];

        $pricing = ProductViewPricing::aggregate($master, $variations);

        self::assertSame(0.0, $pricing->effectivePrice->toGross());
    }

    /*
    |--------------------------------------------------------------------------
    | aggregate — cost price fallback
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function aggregate_falls_back_to_common_variation_cost_when_master_cost_null(): void
    {
        $master = new MasterPricing(
            price: Money::inclusive(20.00),
            effectivePrice: Money::inclusive(18.00),
            costPrice: null,
            profitMargin: null,
        );
        $variations = [
            $this->variation(price: 20.00, effectivePrice: 18.00, costPrice: 4.00),
            $this->variation(price: 20.00, effectivePrice: 18.00, costPrice: 4.00),
        ];

        $pricing = ProductViewPricing::aggregate($master, $variations);

        self::assertSame(4.00, $pricing->costPrice?->toNet());
    }

    #[Test]
    public function aggregate_leaves_cost_null_when_variations_differ(): void
    {
        $master = new MasterPricing(
            price: Money::inclusive(20.00),
            effectivePrice: Money::inclusive(18.00),
            costPrice: null,
            profitMargin: null,
        );
        $variations = [
            $this->variation(price: 20.00, effectivePrice: 18.00, costPrice: 4.00),
            $this->variation(price: 20.00, effectivePrice: 18.00, costPrice: 6.00),
        ];

        $pricing = ProductViewPricing::aggregate($master, $variations);

        self::assertNull($pricing->costPrice);
    }

    #[Test]
    public function aggregate_keeps_master_cost_when_master_cost_set_even_with_variations(): void
    {
        $master = new MasterPricing(
            price: Money::inclusive(20.00),
            effectivePrice: Money::inclusive(18.00),
            costPrice: Money::exclusive(7.50),
            profitMargin: 50.0,
        );
        $variations = [
            $this->variation(price: 20.00, effectivePrice: 18.00, costPrice: 4.00),
            $this->variation(price: 20.00, effectivePrice: 18.00, costPrice: 4.00),
        ];

        $pricing = ProductViewPricing::aggregate($master, $variations);

        self::assertSame(7.50, $pricing->costPrice?->toNet());
    }

    /*
    |--------------------------------------------------------------------------
    | aggregate — profit margin
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function aggregate_recomputes_margin_when_both_common_effective_and_cost_known(): void
    {
        $master = new MasterPricing(
            price: Money::inclusive(0.0),
            effectivePrice: Money::inclusive(0.0),
            costPrice: null,
            profitMargin: 99.99,
        );
        $variations = [
            $this->variation(price: 24.00, effectivePrice: 24.00, costPrice: 10.00),
            $this->variation(price: 24.00, effectivePrice: 24.00, costPrice: 10.00),
        ];

        $pricing = ProductViewPricing::aggregate($master, $variations);

        self::assertSame(50.0, $pricing->profitMargin);
    }

    #[Test]
    public function aggregate_returns_null_margin_when_resolved_cost_is_null(): void
    {
        $master = new MasterPricing(
            price: Money::inclusive(20.00),
            effectivePrice: Money::inclusive(18.00),
            costPrice: null,
            profitMargin: 50.0,
        );
        $variations = [
            $this->variation(price: 20.00, effectivePrice: 18.00, costPrice: 4.00),
            $this->variation(price: 20.00, effectivePrice: 18.00, costPrice: 6.00),
        ];

        $pricing = ProductViewPricing::aggregate($master, $variations);

        self::assertNull($pricing->profitMargin);
    }

    #[Test]
    public function aggregate_keeps_master_margin_when_master_cost_present_and_no_recompute(): void
    {
        $master = new MasterPricing(
            price: Money::inclusive(20.00),
            effectivePrice: Money::inclusive(18.00),
            costPrice: Money::exclusive(7.50),
            profitMargin: 50.0,
        );

        $pricing = ProductViewPricing::aggregate($master, null);

        self::assertSame(50.0, $pricing->profitMargin);
    }

    /*
    |--------------------------------------------------------------------------
    | hasSingleSellingPrice
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_single_selling_price_returns_true_when_variations_null(): void
    {
        self::assertTrue(ProductViewPricing::hasSingleSellingPrice(Money::inclusive(20.00), null));
    }

    #[Test]
    public function has_single_selling_price_returns_true_when_variations_empty(): void
    {
        self::assertTrue(ProductViewPricing::hasSingleSellingPrice(Money::inclusive(20.00), []));
    }

    #[Test]
    public function has_single_selling_price_returns_true_when_all_match_master(): void
    {
        $variations = [
            $this->variation(price: 25.00),
            $this->variation(price: 25.00),
            $this->variation(price: 25.00),
        ];

        self::assertTrue(ProductViewPricing::hasSingleSellingPrice(Money::inclusive(25.00), $variations));
    }

    #[Test]
    public function has_single_selling_price_returns_false_when_any_variation_differs(): void
    {
        $variations = [
            $this->variation(price: 25.00),
            $this->variation(price: 30.00),
        ];

        self::assertFalse(ProductViewPricing::hasSingleSellingPrice(Money::inclusive(25.00), $variations));
    }

    #[Test]
    public function has_single_selling_price_falls_back_to_first_variation_when_master_price_zero(): void
    {
        $variations = [
            $this->variation(price: 15.00),
            $this->variation(price: 15.00),
        ];

        self::assertTrue(ProductViewPricing::hasSingleSellingPrice(Money::inclusive(0.0), $variations));
    }

    #[Test]
    public function has_single_selling_price_returns_false_when_zero_master_and_first_variation_differs(): void
    {
        $variations = [
            $this->variation(price: 15.00),
            $this->variation(price: 25.00),
        ];

        self::assertFalse(ProductViewPricing::hasSingleSellingPrice(Money::inclusive(0.0), $variations));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function variation(
        float $price = 100.0,
        ?float $effectivePrice = null,
        ?float $costPrice = null,
    ): ProductVariationView {
        $effectivePrice ??= $price;
        return new ProductVariationView(
            externalId: 1,
            sku: null,
            gtin: null,
            price: $price,
            costPrice: $costPrice,
            salePrice: null,
            rrp: null,
            effectivePrice: $effectivePrice,
            isOnSale: false,
            profitMargin: null,
            availableStock: 10,
            physicalStock: 10,
            weight: null,
            vatExclusive: false,
            mpn: null,
            imageIndex: null,
            options: [],
            createdAt: new DateTimeImmutable('2026-01-01'),
            updatedAt: new DateTimeImmutable('2026-01-01'),
        );
    }
}
