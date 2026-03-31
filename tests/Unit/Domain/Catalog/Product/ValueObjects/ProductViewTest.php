<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductView::class)]
final class ProductViewTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | hasAnySale (computed in constructor from isOnSale + variations)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_any_sale_true_when_product_is_on_sale(): void
    {
        $view = $this->createView(isOnSale: true, variations: []);

        self::assertTrue($view->hasAnySale);
    }

    #[Test]
    public function has_any_sale_true_when_only_variation_is_on_sale(): void
    {
        $variation = new ProductVariationView(
            id: IntId::from(10),
            sku: null,
            gtin: null,
            price: Money::inclusive(50.00),
            costPrice: null,
            salePrice: Money::inclusive(30.00),
            isOnSale: true,
            profitMargin: null,
            stock: 5,
            weight: null,
            mpn: null,
            imageIndex: null,
            options: [],
        );

        $view = $this->createView(isOnSale: false, variations: [$variation]);

        self::assertFalse($view->isOnSale);
        self::assertTrue($view->hasAnySale);
    }

    #[Test]
    public function has_any_sale_false_when_nothing_is_on_sale(): void
    {
        $variation = new ProductVariationView(
            id: IntId::from(10),
            sku: null,
            gtin: null,
            price: Money::inclusive(50.00),
            costPrice: null,
            salePrice: null,
            isOnSale: false,
            profitMargin: null,
            stock: 5,
            weight: null,
            mpn: null,
            imageIndex: null,
            options: [],
        );

        $view = $this->createView(isOnSale: false, variations: [$variation]);

        self::assertFalse($view->hasAnySale);
    }

    #[Test]
    public function has_any_sale_false_when_variations_null(): void
    {
        $view = $this->createView(isOnSale: false, variations: null);

        self::assertFalse($view->hasAnySale);
    }

    /*
    |--------------------------------------------------------------------------
    | isSaleActive (static utility method)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function is_sale_active_true_when_sale_price_less_than_price(): void
    {
        self::assertTrue(ProductView::isSaleActive(
            Money::inclusive(75.00),
            Money::inclusive(100.00),
        ));
    }

    #[Test]
    public function is_sale_active_false_when_sale_price_null(): void
    {
        self::assertFalse(ProductView::isSaleActive(null, Money::inclusive(100.00)));
    }

    #[Test]
    public function is_sale_active_false_when_sale_price_zero(): void
    {
        self::assertFalse(ProductView::isSaleActive(
            Money::inclusive(0.0),
            Money::inclusive(100.00),
        ));
    }

    #[Test]
    public function is_sale_active_false_when_sale_price_equals_price(): void
    {
        self::assertFalse(ProductView::isSaleActive(
            Money::inclusive(100.00),
            Money::inclusive(100.00),
        ));
    }

    #[Test]
    public function is_sale_active_false_when_sale_price_exceeds_price(): void
    {
        self::assertFalse(ProductView::isSaleActive(
            Money::inclusive(120.00),
            Money::inclusive(100.00),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | retailMargin (static utility method)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function retail_margin_calculates_percentage(): void
    {
        // (100 - 60) / 100 × 100 = 40%
        self::assertSame(40.0, ProductView::retailMargin(
            Money::inclusive(100.00),
            Money::inclusive(60.00),
        ));
    }

    #[Test]
    public function retail_margin_null_when_cost_null(): void
    {
        self::assertNull(ProductView::retailMargin(Money::inclusive(100.00), null));
    }

    #[Test]
    public function retail_margin_null_when_price_zero(): void
    {
        self::assertNull(ProductView::retailMargin(
            Money::inclusive(0.0),
            Money::inclusive(10.00),
        ));
    }

    #[Test]
    public function retail_margin_negative_when_cost_exceeds_price(): void
    {
        // (50 - 75) / 50 × 100 = -50%
        self::assertSame(-50.0, ProductView::retailMargin(
            Money::inclusive(50.00),
            Money::inclusive(75.00),
        ));
    }

    #[Test]
    public function retail_margin_rounds_to_two_decimals(): void
    {
        // (30 - 19.99) / 30 × 100 = 33.366...%
        self::assertSame(33.37, ProductView::retailMargin(
            Money::inclusive(30.00),
            Money::inclusive(19.99),
        ));
    }

    #[Test]
    public function retail_margin_handles_exclusive_tax_prices(): void
    {
        // Exclusive £20 net, exclusive £10 net
        // (20 - 10) / 20 × 100 = 50%
        self::assertSame(50.0, ProductView::retailMargin(
            Money::exclusive(20.00),
            Money::exclusive(10.00),
        ));
    }

    #[Test]
    public function retail_margin_with_zero_rated_price_and_exclusive_cost(): void
    {
        // Real-world: zero-rated product (book) with Linnworks cost (always exclusive)
        // Net: (8 - 5) / 8 × 100 = 37.5%
        // Would incorrectly return 25% if using toGross() (phantom VAT inflates cost to £6)
        self::assertSame(37.5, ProductView::retailMargin(
            Money::zeroRated(8.00),
            Money::exclusive(5.00),
        ));
    }

    #[Test]
    public function retail_margin_with_inclusive_price_and_exclusive_cost(): void
    {
        // Real-world: standard-rated product with Linnworks cost (always exclusive)
        // Net price: £24 / 1.2 = £20, net cost: £10
        // (20 - 10) / 20 × 100 = 50%
        self::assertSame(50.0, ProductView::retailMargin(
            Money::inclusive(24.00),
            Money::exclusive(10.00),
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param list<ProductVariationView>|null $variations
     */
    private function createView(
        ?Money $price = null,
        ?Money $costPrice = null,
        ?Money $salePrice = null,
        ?array $variations = null,
        bool $isOnSale = false,
        ?float $profitMargin = null,
    ): ProductView {
        return new ProductView(
            id: IntId::from(1),
            sku: null,
            gtin: null,
            title: 'Test Product',
            description: null,
            slug: 'test-product',
            url: 'https://example.com/test-product',
            price: $price ?? Money::inclusive(100.00),
            costPrice: $costPrice,
            salePrice: $salePrice,
            comparePrice: null,
            isOnSale: $isOnSale,
            profitMargin: $profitMargin,
            stock: 10,
            isActive: true,
            vatExclusive: false,
            vatRelief: false,
            weight: null,
            metaTitle: null,
            metaDescription: null,
            categoryIds: [],
            variations: $variations,
            images: [],
            customFields: [],
            filters: [],
            sortOrder: null,
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
        );
    }
}
