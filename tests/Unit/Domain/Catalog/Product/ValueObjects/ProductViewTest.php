<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\Shared\ValueObjects\DateFormat;
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
            externalId: 10,
            sku: null,
            gtin: null,
            price: 50.00,
            costPrice: null,
            salePrice: 30.00,
            rrp: null,
            effectivePrice: 30.00,
            isOnSale: true,
            profitMargin: null,
            stock: 5,
            weight: null,
            vatExclusive: false,
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
            externalId: 10,
            sku: null,
            gtin: null,
            price: 50.00,
            costPrice: null,
            salePrice: null,
            rrp: null,
            effectivePrice: 50.00,
            isOnSale: false,
            profitMargin: null,
            stock: 5,
            weight: null,
            vatExclusive: false,
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
    | hasFreeDelivery (computed from FreeDeliveryType)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_free_delivery_true_when_standard(): void
    {
        $view = $this->createView(freeDelivery: FreeDeliveryType::Standard);

        self::assertTrue($view->hasFreeDelivery);
    }

    #[Test]
    public function has_free_delivery_true_when_express(): void
    {
        $view = $this->createView(freeDelivery: FreeDeliveryType::Express);

        self::assertTrue($view->hasFreeDelivery);
    }

    #[Test]
    public function has_free_delivery_false_when_none(): void
    {
        $view = $this->createView(freeDelivery: FreeDeliveryType::None);

        self::assertFalse($view->hasFreeDelivery);
    }

    #[Test]
    public function has_free_delivery_false_when_null(): void
    {
        $view = $this->createView(freeDelivery: null);

        self::assertFalse($view->hasFreeDelivery);
    }

    /*
    |--------------------------------------------------------------------------
    | UK-formatted dates
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function created_at_formatted_uses_uk_date_format(): void
    {
        $createdAt = new DateTimeImmutable('2024-03-15');
        $view = $this->createView(createdAt: $createdAt);

        self::assertSame('15/03/2024', $view->createdAtFormatted);
    }

    #[Test]
    public function updated_at_formatted_uses_uk_date_format(): void
    {
        $updatedAt = new DateTimeImmutable('2025-12-01');
        $view = $this->createView(updatedAt: $updatedAt);

        self::assertSame('01/12/2025', $view->updatedAtFormatted);
    }

    #[Test]
    public function formatted_dates_use_default_date_format_constant(): void
    {
        $date = new DateTimeImmutable('2024-07-04');
        $view = $this->createView(createdAt: $date, updatedAt: $date);

        self::assertSame($date->format(DateFormat::DEFAULT_DATE_FORMAT), $view->createdAtFormatted);
        self::assertSame($date->format(DateFormat::DEFAULT_DATE_FORMAT), $view->updatedAtFormatted);
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
    public function self_constructs_sku_from_string(): void
    {
        $view = $this->createView(sku: 'ABC-123');

        self::assertSame('ABC-123', $view->sku?->value);
    }

    #[Test]
    public function sku_null_for_empty_string(): void
    {
        $view = $this->createView(sku: '');

        self::assertNull($view->sku);
    }

    #[Test]
    public function self_constructs_price_as_money(): void
    {
        $view = $this->createView(price: 99.99);

        self::assertSame(99.99, $view->price->toGross());
    }

    #[Test]
    public function self_constructs_effective_price_as_money(): void
    {
        $view = $this->createView(effectivePrice: 79.99);

        self::assertSame(79.99, $view->effectivePrice->toGross());
    }

    #[Test]
    public function self_constructs_category_ids_from_ints(): void
    {
        $view = $this->createView(categoryIds: [10, 20, 30]);

        self::assertCount(3, $view->categoryIds);
        self::assertSame(10, $view->categoryIds[0]->value);
        self::assertSame(30, $view->categoryIds[2]->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param list<ProductVariationView>|null $variations
     * @param list<int> $categoryIds
     */
    private function createView(
        float $price = 100.00,
        ?float $costPrice = null,
        ?float $salePrice = null,
        float $effectivePrice = 100.00,
        ?array $variations = null,
        bool $isOnSale = false,
        ?float $profitMargin = null,
        ?string $sku = null,
        array $categoryIds = [],
        ?FreeDeliveryType $freeDelivery = null,
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $updatedAt = null,
    ): ProductView {
        return new ProductView(
            externalId: 1,
            sku: $sku,
            gtin: null,
            title: 'Test Product',
            description: null,
            slug: 'test-product',
            url: 'https://example.com/test-product',
            price: $price,
            costPrice: $costPrice,
            salePrice: $salePrice,
            rrp: null,
            effectivePrice: $effectivePrice,
            isOnSale: $isOnSale,
            profitMargin: $profitMargin,
            isActive: true,
            vatExclusive: false,
            vatRelief: false,
            metaTitle: null,
            metaDescription: null,
            categoryIds: $categoryIds,
            variations: $variations,
            images: [],
            customFields: [],
            filters: [],
            sortOrder: null,
            createdAt: $createdAt ?? new DateTimeImmutable('2024-01-01'),
            updatedAt: $updatedAt ?? new DateTimeImmutable('2024-01-01'),
            freeDelivery: $freeDelivery,
        );
    }
}
