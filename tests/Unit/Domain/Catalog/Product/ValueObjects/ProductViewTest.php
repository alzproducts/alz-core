<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\StringCustomFieldValue;
use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use App\Domain\Catalog\Product\ValueObjects\ProductLinks;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\Catalog\Product\ValueObjects\ProductViewMeta;
use App\Domain\Shared\ValueObjects\DateFormat;
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
    | hasAnySale (derived from isOnSale + pre-computed hasAnyVariationOnSale)
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
            availableStock: 5,
            physicalStock: 5,
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
            availableStock: 5,
            physicalStock: 5,
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
    | hasSingleSellingPrice
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_single_selling_price_true_when_no_variations(): void
    {
        $view = $this->createView(variations: []);

        self::assertTrue($view->hasSingleSellingPrice());
    }

    #[Test]
    public function has_single_selling_price_true_when_all_variations_match_master(): void
    {
        $view = $this->createView(
            price: 29.99,
            variations: [
                $this->createVariation(price: 29.99),
                $this->createVariation(price: 29.99),
            ],
        );

        self::assertTrue($view->hasSingleSellingPrice());
    }

    #[Test]
    public function has_single_selling_price_false_when_variations_differ_from_master(): void
    {
        $view = $this->createView(
            price: 29.99,
            variations: [
                $this->createVariation(price: 29.99),
                $this->createVariation(price: 39.99),
            ],
        );

        self::assertFalse($view->hasSingleSellingPrice());
    }

    #[Test]
    public function has_single_selling_price_true_when_master_zero_and_all_variations_equal(): void
    {
        $view = $this->createView(
            price: 0.00,
            effectivePrice: 0.00,
            variations: [
                $this->createVariation(price: 29.99),
                $this->createVariation(price: 29.99),
            ],
        );

        self::assertTrue($view->hasSingleSellingPrice());
    }

    #[Test]
    public function has_single_selling_price_false_when_master_zero_and_variations_differ(): void
    {
        $view = $this->createView(
            price: 0.00,
            effectivePrice: 0.00,
            variations: [
                $this->createVariation(price: 29.99),
                $this->createVariation(price: 39.99),
            ],
        );

        self::assertFalse($view->hasSingleSellingPrice());
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
    | isInCategory
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function is_in_category_true_when_id_present(): void
    {
        $view = $this->createView(categoryIds: [10, 20, 30]);

        self::assertTrue($view->isInCategory(IntId::from(20)));
    }

    #[Test]
    public function is_in_category_false_when_id_absent(): void
    {
        $view = $this->createView(categoryIds: [10, 20, 30]);

        self::assertFalse($view->isInCategory(IntId::from(99)));
    }

    #[Test]
    public function is_in_category_false_when_category_ids_empty(): void
    {
        $view = $this->createView(categoryIds: []);

        self::assertFalse($view->isInCategory(IntId::from(10)));
    }

    /*
    |--------------------------------------------------------------------------
    | getCustomField / hasCustomField
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_custom_field_returns_matching_field_by_name(): void
    {
        $field = $this->stringCustomField('sale_reason', 'Spring clearance');
        $view = $this->createView(customFields: [$field]);

        self::assertSame($field, $view->getCustomField('sale_reason'));
    }

    #[Test]
    public function get_custom_field_returns_null_when_name_not_found(): void
    {
        $view = $this->createView(customFields: [
            $this->stringCustomField('sale_reason', 'Spring clearance'),
        ]);

        self::assertNull($view->getCustomField('missing_field'));
    }

    #[Test]
    public function has_custom_field_true_when_field_present(): void
    {
        $view = $this->createView(customFields: [
            $this->stringCustomField('discontinued', 'yes'),
        ]);

        self::assertTrue($view->hasCustomField('discontinued'));
    }

    #[Test]
    public function has_custom_field_false_when_field_missing(): void
    {
        $view = $this->createView(customFields: []);

        self::assertFalse($view->hasCustomField('discontinued'));
    }

    /*
    |--------------------------------------------------------------------------
    | stockLevel
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function stock_level_sums_variations_when_present(): void
    {
        $view = $this->createView(variations: [
            $this->createVariation(availableStock: 3, physicalStock: 4),
            $this->createVariation(availableStock: 7, physicalStock: 9),
            $this->createVariation(availableStock: 0, physicalStock: 1),
        ]);

        self::assertSame(10, $view->stockLevel->availableStock);
        self::assertSame(14, $view->stockLevel->physicalStock);
    }

    #[Test]
    public function stock_level_uses_parent_values_when_no_variations(): void
    {
        $view = $this->createView(variations: [], parentAvailableStock: 42, parentPhysicalStock: 50);

        self::assertSame(42, $view->stockLevel->availableStock);
        self::assertSame(50, $view->stockLevel->physicalStock);
    }

    #[Test]
    public function stock_level_zero_when_no_variations_and_parent_stock_zero(): void
    {
        $view = $this->createView(variations: [], parentAvailableStock: 0, parentPhysicalStock: 0);

        self::assertSame(0, $view->stockLevel->availableStock);
        self::assertSame(0, $view->stockLevel->physicalStock);
    }

    /*
    |--------------------------------------------------------------------------
    | allOnSaleSkus
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function all_on_sale_skus_includes_master_when_on_sale(): void
    {
        $view = $this->createView(
            sku: 'MASTER-SKU',
            isOnSale: true,
            variations: [],
        );

        $skus = $view->allOnSaleSkus();

        self::assertCount(1, $skus);
        self::assertSame('MASTER-SKU', $skus[0]->value);
    }

    #[Test]
    public function all_on_sale_skus_excludes_master_when_not_on_sale(): void
    {
        $view = $this->createView(
            sku: 'MASTER-SKU',
            isOnSale: false,
            variations: [],
        );

        self::assertSame([], $view->allOnSaleSkus());
    }

    #[Test]
    public function all_on_sale_skus_includes_variation_on_sale(): void
    {
        $variation = new ProductVariationView(
            externalId: 10,
            sku: 'VAR-SKU-1',
            gtin: null,
            price: 50.00,
            costPrice: null,
            salePrice: 30.00,
            rrp: null,
            effectivePrice: 30.00,
            isOnSale: true,
            profitMargin: null,
            availableStock: 5,
            physicalStock: 5,
            weight: null,
            vatExclusive: false,
            mpn: null,
            imageIndex: null,
            options: [],
        );

        $view = $this->createView(
            sku: 'MASTER-SKU',
            isOnSale: false,
            variations: [$variation],
        );

        $skus = $view->allOnSaleSkus();

        self::assertCount(1, $skus);
        self::assertSame('VAR-SKU-1', $skus[0]->value);
    }

    #[Test]
    public function all_on_sale_skus_collects_master_and_variations(): void
    {
        $variationOnSale = new ProductVariationView(
            externalId: 10,
            sku: 'VAR-SKU-1',
            gtin: null,
            price: 50.00,
            costPrice: null,
            salePrice: 30.00,
            rrp: null,
            effectivePrice: 30.00,
            isOnSale: true,
            profitMargin: null,
            availableStock: 5,
            physicalStock: 5,
            weight: null,
            vatExclusive: false,
            mpn: null,
            imageIndex: null,
            options: [],
        );
        $variationNotOnSale = new ProductVariationView(
            externalId: 11,
            sku: 'VAR-SKU-2',
            gtin: null,
            price: 50.00,
            costPrice: null,
            salePrice: null,
            rrp: null,
            effectivePrice: 50.00,
            isOnSale: false,
            profitMargin: null,
            availableStock: 5,
            physicalStock: 5,
            weight: null,
            vatExclusive: false,
            mpn: null,
            imageIndex: null,
            options: [],
        );

        $view = $this->createView(
            sku: 'MASTER-SKU',
            isOnSale: true,
            variations: [$variationOnSale, $variationNotOnSale],
        );

        $skus = $view->allOnSaleSkus();

        self::assertCount(2, $skus);
        self::assertSame('MASTER-SKU', $skus[0]->value);
        self::assertSame('VAR-SKU-1', $skus[1]->value);
    }

    #[Test]
    public function all_on_sale_skus_skips_null_master_sku(): void
    {
        $view = $this->createView(
            sku: null,
            isOnSale: true,
            variations: [],
        );

        self::assertSame([], $view->allOnSaleSkus());
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param list<ProductVariationView>|null $variations
     * @param list<int> $categoryIds
     * @param list<AbstractCustomFieldValue> $customFields
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
        array $customFields = [],
        int $parentAvailableStock = 0,
        int $parentPhysicalStock = 0,
    ): ProductView {
        return new ProductView(
            externalId: 1,
            sku: $sku,
            gtin: null,
            title: 'Test Product',
            description: null,
            slug: 'test-product',
            links: new ProductLinks(
                publicUrl: 'https://example.com/test-product',
                editWebsiteUrl: 'https://admin.myshopwired.uk/business/manage-ecommerce-add-product/1',
            ),
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
            customFields: $customFields,
            filters: [],
            sortOrder: null,
            createdAt: $createdAt ?? new DateTimeImmutable('2024-01-01'),
            updatedAt: $updatedAt ?? new DateTimeImmutable('2024-01-01'),
            meta: new ProductViewMeta($variations, null, null),
            hasAnyVariationOnSale: ProductVariationView::anyOnSale($variations),
            parentAvailableStock: $parentAvailableStock,
            parentPhysicalStock: $parentPhysicalStock,
            freeDelivery: $freeDelivery,
        );
    }

    private function stringCustomField(string $name, string $value): StringCustomFieldValue
    {
        return new StringCustomFieldValue(
            new CustomFieldDefinition(
                id: 1,
                name: $name,
                type: CustomFieldType::Text,
                label: null,
                itemType: CustomFieldItemType::Product,
                sortOrder: null,
                allowedValues: null,
            ),
            $value,
        );
    }

    private function createVariation(
        float $price = 50.00,
        ?float $rrp = null,
        int $availableStock = 5,
        int $physicalStock = 5,
    ): ProductVariationView {
        static $id = 100;

        return new ProductVariationView(
            externalId: $id++,
            sku: null,
            gtin: null,
            price: $price,
            costPrice: null,
            salePrice: null,
            rrp: $rrp,
            effectivePrice: $price,
            isOnSale: false,
            profitMargin: null,
            availableStock: $availableStock,
            physicalStock: $physicalStock,
            weight: null,
            vatExclusive: false,
            mpn: null,
            imageIndex: null,
            options: [],
        );
    }
}
