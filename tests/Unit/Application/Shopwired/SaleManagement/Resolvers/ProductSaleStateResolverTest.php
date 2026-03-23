<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\SaleManagement\Resolvers;

use App\Application\Shopwired\SaleManagement\Resolvers\ProductSaleStateResolver;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ProductSaleStateResolver::class)]
final class ProductSaleStateResolverTest extends TestCase
{
    private const int SALE_CATEGORY_ID = 999;

    private ProductSaleStateResolver $specification;

    protected function setUp(): void
    {
        parent::setUp();

        $this->specification = new ProductSaleStateResolver(
            saleCategoryId: self::SALE_CATEGORY_ID,
        );
    }

    // ========================================================================
    // On sale, fully correct — no correction needed
    // ========================================================================

    #[Test]
    public function product_on_sale_in_category_with_custom_fields_needs_no_correction(): void
    {
        $product = self::createProduct(
            salePrice: 15.00,
            categoryIds: [self::SALE_CATEGORY_ID],
            rawCustomFields: ['sale_reason' => 'Test Sale'],
        );

        $result = $this->specification->evaluate($product);

        self::assertTrue($result->shouldBeOnSale);
        self::assertFalse($result->needsAddToSale);
        self::assertFalse($result->needsRemoveFromSale);
    }

    // ========================================================================
    // On sale, missing category — needs add
    // ========================================================================

    #[Test]
    public function product_on_sale_not_in_sale_category_needs_add(): void
    {
        $product = self::createProduct(
            salePrice: 15.00,
            categoryIds: [100, 200],
            rawCustomFields: ['sale_reason' => 'Test Sale'],
        );

        $result = $this->specification->evaluate($product);

        self::assertTrue($result->shouldBeOnSale);
        self::assertTrue($result->needsAddToSale);
        self::assertFalse($result->needsRemoveFromSale);
    }

    // ========================================================================
    // On sale, in category, but missing custom fields — needs add
    // ========================================================================

    #[Test]
    public function product_on_sale_in_category_without_custom_fields_needs_add(): void
    {
        $product = self::createProduct(
            salePrice: 15.00,
            categoryIds: [self::SALE_CATEGORY_ID],
            rawCustomFields: [],
        );

        $result = $this->specification->evaluate($product);

        self::assertTrue($result->shouldBeOnSale);
        self::assertTrue($result->needsAddToSale);
        self::assertFalse($result->needsRemoveFromSale);
    }

    // ========================================================================
    // Not on sale (salePrice=null), but in sale category — needs remove
    // ========================================================================

    #[Test]
    public function product_not_on_sale_null_price_in_sale_category_needs_remove(): void
    {
        $product = self::createProduct(
            salePrice: null,
            categoryIds: [self::SALE_CATEGORY_ID],
            rawCustomFields: [],
        );

        $result = $this->specification->evaluate($product);

        self::assertFalse($result->shouldBeOnSale);
        self::assertFalse($result->needsAddToSale);
        self::assertTrue($result->needsRemoveFromSale);
    }

    // ========================================================================
    // Not on sale (salePrice=0), has sale custom fields — needs remove
    // ========================================================================

    #[Test]
    public function product_not_on_sale_zero_price_with_custom_fields_needs_remove(): void
    {
        $product = self::createProduct(
            salePrice: 0.00,
            categoryIds: [],
            rawCustomFields: ['sale_reason' => 'Old Sale'],
        );

        $result = $this->specification->evaluate($product);

        self::assertFalse($result->shouldBeOnSale);
        self::assertFalse($result->needsAddToSale);
        self::assertTrue($result->needsRemoveFromSale);
    }

    // ========================================================================
    // Not on sale, not in category, no custom fields — no correction
    // ========================================================================

    #[Test]
    public function product_not_on_sale_not_in_category_no_custom_fields_needs_no_correction(): void
    {
        $product = self::createProduct(
            salePrice: null,
            categoryIds: [],
            rawCustomFields: [],
        );

        $result = $this->specification->evaluate($product);

        self::assertFalse($result->shouldBeOnSale);
        self::assertFalse($result->needsAddToSale);
        self::assertFalse($result->needsRemoveFromSale);
    }

    // ========================================================================
    // salePrice >= price — NOT on sale
    // ========================================================================

    #[Test]
    public function sale_price_equal_to_price_is_not_on_sale(): void
    {
        $product = self::createProduct(
            price: 20.00,
            salePrice: 20.00,
            categoryIds: [self::SALE_CATEGORY_ID],
            rawCustomFields: ['sale_reason' => 'Stale'],
        );

        $result = $this->specification->evaluate($product);

        self::assertFalse($result->shouldBeOnSale);
        self::assertTrue($result->needsRemoveFromSale);
    }

    #[Test]
    public function sale_price_greater_than_price_is_not_on_sale(): void
    {
        $product = self::createProduct(
            price: 20.00,
            salePrice: 25.00,
            categoryIds: [self::SALE_CATEGORY_ID],
            rawCustomFields: ['sale_comments' => 'Leftover'],
        );

        $result = $this->specification->evaluate($product);

        self::assertFalse($result->shouldBeOnSale);
        self::assertTrue($result->needsRemoveFromSale);
    }

    // ========================================================================
    // SKU sale states match shouldBeOnSale
    // ========================================================================

    #[Test]
    public function sku_sale_states_reflect_should_be_on_sale_for_master_sku(): void
    {
        $product = self::createProduct(
            salePrice: 15.00,
            categoryIds: [self::SALE_CATEGORY_ID],
            rawCustomFields: ['sale_reason' => 'Test'],
        );

        $result = $this->specification->evaluate($product);

        self::assertCount(1, $result->skuSaleStates);
        self::assertSame('MASTER-001', $result->skuSaleStates[0]->sku->value);
        self::assertTrue($result->skuSaleStates[0]->shouldBeInSale);
    }

    #[Test]
    public function sku_sale_states_reflect_not_on_sale_for_master_sku(): void
    {
        $product = self::createProduct(
            salePrice: null,
            categoryIds: [],
            rawCustomFields: [],
        );

        $result = $this->specification->evaluate($product);

        self::assertCount(1, $result->skuSaleStates);
        self::assertSame('MASTER-001', $result->skuSaleStates[0]->sku->value);
        self::assertFalse($result->skuSaleStates[0]->shouldBeInSale);
    }

    // ========================================================================
    // Product with variations — skuSaleStates has entries for all SKUs
    // ========================================================================

    #[Test]
    public function sku_sale_states_include_master_and_variation_skus(): void
    {
        $variations = [
            new ProductVariation(
                id: 1,
                productExternalId: 1,
                sku: 'VAR-001',
                price: 25.00,
                costPrice: null,
                salePrice: 20.00,
                stock: 50,
                weight: null,
                gtin: null,
                mpn: null,
                imageIndex: null,
            ),
            new ProductVariation(
                id: 2,
                productExternalId: 1,
                sku: 'VAR-002',
                price: 30.00,
                costPrice: null,
                salePrice: 25.00,
                stock: 30,
                weight: null,
                gtin: null,
                mpn: null,
                imageIndex: null,
            ),
        ];

        $product = self::createProduct(
            salePrice: 15.00,
            categoryIds: [self::SALE_CATEGORY_ID],
            rawCustomFields: ['sale_reason' => 'Test'],
            variations: $variations,
        );

        $result = $this->specification->evaluate($product);

        self::assertCount(3, $result->skuSaleStates);

        $skuValues = \array_map(
            static fn($state): string => $state->sku->value,
            $result->skuSaleStates,
        );

        self::assertSame(['MASTER-001', 'VAR-001', 'VAR-002'], $skuValues);
        self::assertTrue($result->skuSaleStates[0]->shouldBeInSale);
        self::assertTrue($result->skuSaleStates[1]->shouldBeInSale);
        self::assertTrue($result->skuSaleStates[2]->shouldBeInSale);
    }

    // ========================================================================
    // hasSaleCustomFields excludes DefaultSortOrder
    // ========================================================================

    #[Test]
    public function default_sort_order_alone_does_not_count_as_sale_custom_field(): void
    {
        $product = self::createProduct(
            salePrice: null,
            categoryIds: [],
            rawCustomFields: ['default_sort_order' => '5'],
        );

        $result = $this->specification->evaluate($product);

        self::assertFalse($result->shouldBeOnSale);
        self::assertFalse($result->needsRemoveFromSale);
    }

    #[Test]
    public function empty_sale_custom_field_values_do_not_count(): void
    {
        $product = self::createProduct(
            salePrice: null,
            categoryIds: [],
            rawCustomFields: ['sale_reason' => '', 'sale_comments' => ''],
        );

        $result = $this->specification->evaluate($product);

        self::assertFalse($result->shouldBeOnSale);
        self::assertFalse($result->needsRemoveFromSale);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * @param list<int>           $categoryIds
     * @param array<string, mixed> $rawCustomFields
     * @param list<ProductVariation>|null $variations
     */
    private static function createProduct(
        ?float $salePrice,
        array $categoryIds,
        array $rawCustomFields,
        float $price = 20.00,
        ?array $variations = null,
    ): Product {
        return new Product(
            id: 1,
            sku: 'MASTER-001',
            gtin: null,
            title: 'Test Product',
            description: null,
            slug: 'test',
            url: 'https://example.com',
            price: $price,
            costPrice: null,
            salePrice: $salePrice,
            comparePrice: null,
            stock: 100,
            isActive: true,
            vatExclusive: false,
            vatRelief: false,
            weight: null,
            metaTitle: null,
            metaDescription: null,
            categoryIds: $categoryIds,
            variations: $variations,
            images: [],
            rawCustomFields: $rawCustomFields,
            customFields: [],
            rawFilters: [],
            filters: [],
            sortOrder: null,
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
        );
    }
}
