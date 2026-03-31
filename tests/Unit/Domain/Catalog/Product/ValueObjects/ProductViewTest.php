<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
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
    public function self_constructs_weight_from_float(): void
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
        ?float $weight = null,
        array $categoryIds = [],
        ?FreeDeliveryType $freeDelivery = null,
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
            comparePrice: null,
            effectivePrice: $effectivePrice,
            isOnSale: $isOnSale,
            profitMargin: $profitMargin,
            stock: 10,
            isActive: true,
            vatExclusive: false,
            vatRelief: false,
            weight: $weight,
            metaTitle: null,
            metaDescription: null,
            categoryIds: $categoryIds,
            variations: $variations,
            images: [],
            customFields: [],
            filters: [],
            sortOrder: null,
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
            freeDelivery: $freeDelivery,
        );
    }
}
