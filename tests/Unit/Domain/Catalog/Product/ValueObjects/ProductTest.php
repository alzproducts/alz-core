<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\Filters\ValueObjects\FilterGroupDefinition;
use App\Domain\Catalog\Filters\ValueObjects\ProductFilter;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductImage;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Product::class)]
final class ProductTest extends TestCase
{
    // ========================================================================
    // Factory Helper
    // ========================================================================

    /**
     * Create a product with sensible defaults for testing.
     *
     * @param array<string, mixed> $overrides
     * @param list<ProductVariation> $variations
     * @param list<ProductImage> $images
     * @param list<AbstractCustomFieldValue> $customFields
     */
    private static function createProduct(
        array $overrides = [],
        array $variations = [],
        array $images = [],
        array $customFields = [],
    ): Product {
        $defaults = [
            'id' => 12345,
            'sku' => 'TEST-SKU-001',
            'gtin' => null,
            'title' => 'Test Product',
            'description' => 'A test product description',
            'slug' => 'test-product',
            'url' => 'https://example.com/products/test-product',
            'price' => 29.99,
            'costPrice' => 15.00,
            'salePrice' => null,
            'comparePrice' => null,
            'stock' => 100,
            'isActive' => true,
            'vatExclusive' => false,
            'vatRelief' => false,
            'weight' => 0.5,
            'metaTitle' => 'Test Product | Shop',
            'metaDescription' => 'Buy Test Product online',
            'categoryIds' => [1, 2, 3],
            'variations' => $variations,
            'images' => $images,
            'rawCustomFields' => [],
            'customFields' => $customFields,
            'rawFilters' => [],
            'filters' => [],
            'createdAt' => new DateTimeImmutable('2024-01-01 12:00:00'),
            'updatedAt' => new DateTimeImmutable('2024-01-15 14:30:00'),
        ];

        $data = [...$defaults, ...$overrides];

        return new Product(
            id: $data['id'],
            sku: $data['sku'],
            gtin: $data['gtin'],
            title: $data['title'],
            description: $data['description'],
            slug: $data['slug'],
            url: $data['url'],
            price: $data['price'],
            costPrice: $data['costPrice'],
            salePrice: $data['salePrice'],
            comparePrice: $data['comparePrice'],
            stock: $data['stock'],
            isActive: $data['isActive'],
            vatExclusive: $data['vatExclusive'],
            vatRelief: $data['vatRelief'],
            weight: $data['weight'],
            metaTitle: $data['metaTitle'],
            metaDescription: $data['metaDescription'],
            categoryIds: $data['categoryIds'],
            variations: $data['variations'],
            images: $data['images'],
            rawCustomFields: $data['rawCustomFields'],
            customFields: $data['customFields'],
            rawFilters: $data['rawFilters'],
            filters: $data['filters'],
            sortOrder: null,
            createdAt: $data['createdAt'],
            updatedAt: $data['updatedAt'],
        );
    }

    /**
     * Create a variation for testing.
     */
    private static function createVariation(
        int $id,
        string $sku,
        int $stock,
        ?float $price = 29.99,
        ?float $salePrice = null,
    ): ProductVariation {
        return new ProductVariation(
            id: $id,
            productExternalId: 12345,
            sku: $sku,
            price: $price,
            costPrice: 15.00,
            salePrice: $salePrice,
            stock: $stock,
            weight: 0.5,
            gtin: null,
            mpn: null,
            imageIndex: null,
        );
    }

    // ========================================================================
    // Constructor Validation
    // ========================================================================

    #[Test]
    public function it_creates_valid_product(): void
    {
        // Act
        $product = self::createProduct();

        // Assert
        self::assertSame(12345, $product->id);
        self::assertSame('TEST-SKU-001', $product->sku);
        self::assertSame('Test Product', $product->title);
        self::assertSame(29.99, $product->price);
        self::assertSame(100, $product->stock);
        self::assertTrue($product->isActive);
    }

    #[Test]
    public function it_rejects_non_positive_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Product ID must be positive');

        self::createProduct(['id' => 0]);
    }

    #[Test]
    public function it_rejects_empty_title(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Product title cannot be empty');

        self::createProduct(['title' => '']);
    }

    #[Test]
    public function it_rejects_empty_slug(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Product slug cannot be empty');

        self::createProduct(['slug' => '']);
    }

    #[Test]
    public function it_rejects_negative_price(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Price cannot be negative');

        self::createProduct(['price' => -0.01]);
    }

    #[Test]
    public function it_allows_zero_price(): void
    {
        // Act - free products should be allowed
        $product = self::createProduct(['price' => 0.0]);

        // Assert
        self::assertSame(0.0, $product->price);
    }

    #[Test]
    public function it_allows_negative_stock_for_backorders(): void
    {
        // ShopWired supports backorders, so negative stock is valid business data

        // Act
        $product = self::createProduct(['stock' => -5]);

        // Assert
        self::assertSame(-5, $product->stock);
    }

    #[Test]
    public function it_allows_null_optional_fields(): void
    {
        // Act
        $product = self::createProduct([
            'sku' => null,
            'gtin' => null,
            'description' => null,
            'costPrice' => null,
            'salePrice' => null,
            'comparePrice' => null,
            'weight' => null,
            'metaTitle' => null,
            'metaDescription' => null,
        ]);

        // Assert
        self::assertNull($product->sku);
        self::assertNull($product->description);
        self::assertNull($product->costPrice);
        self::assertNull($product->weight);
    }

    // ========================================================================
    // Variation Methods
    // ========================================================================

    #[Test]
    public function has_variations_returns_false_for_simple_product(): void
    {
        // Arrange
        $product = self::createProduct();

        // Assert
        self::assertFalse($product->hasVariations());
    }

    #[Test]
    public function has_variations_returns_true_when_variations_exist(): void
    {
        // Arrange
        $variations = [
            self::createVariation(1, 'VAR-001', 50),
            self::createVariation(2, 'VAR-002', 30),
        ];
        $product = self::createProduct(variations: $variations);

        // Assert
        self::assertTrue($product->hasVariations());
    }

    // ========================================================================
    // Stock Calculation
    // ========================================================================

    #[Test]
    public function total_stock_returns_master_stock_for_simple_product(): void
    {
        // Arrange
        $product = self::createProduct(['stock' => 75]);

        // Act & Assert
        self::assertSame(75, $product->totalStock());
    }

    #[Test]
    public function total_stock_sums_variation_stocks(): void
    {
        // Arrange
        $variations = [
            self::createVariation(1, 'VAR-001', 50),
            self::createVariation(2, 'VAR-002', 30),
            self::createVariation(3, 'VAR-003', 20),
        ];
        $product = self::createProduct(['stock' => 0], variations: $variations);

        // Act & Assert - should be 50 + 30 + 20 = 100
        self::assertSame(100, $product->totalStock());
    }

    #[Test]
    public function total_stock_handles_negative_variation_stock(): void
    {
        // ShopWired can have negative stock (backorders)

        // Arrange
        $variations = [
            self::createVariation(1, 'VAR-001', 50),
            self::createVariation(2, 'VAR-002', -10), // Oversold
        ];
        $product = self::createProduct(variations: $variations);

        // Act & Assert - 50 + (-10) = 40
        self::assertSame(40, $product->totalStock());
    }

    #[Test]
    public function is_in_stock_true_when_positive_stock(): void
    {
        $product = self::createProduct(['stock' => 1]);
        self::assertTrue($product->isInStock());
    }

    #[Test]
    public function is_in_stock_false_when_zero_stock(): void
    {
        $product = self::createProduct(['stock' => 0]);
        self::assertFalse($product->isInStock());
    }

    #[Test]
    public function is_in_stock_false_when_negative_stock(): void
    {
        $product = self::createProduct(['stock' => -5]);
        self::assertFalse($product->isInStock());
    }

    #[Test]
    public function is_in_stock_uses_variation_totals(): void
    {
        // Arrange - master stock is 0, but variations have stock
        $variations = [
            self::createVariation(1, 'VAR-001', 10),
        ];
        $product = self::createProduct(['stock' => 0], variations: $variations);

        // Assert
        self::assertTrue($product->isInStock());
    }

    #[Test]
    public function get_stock_level_returns_total_stock(): void
    {
        // Arrange
        $variations = [
            self::createVariation(1, 'VAR-001', 25),
            self::createVariation(2, 'VAR-002', 15),
        ];
        $product = self::createProduct(variations: $variations);

        // Assert
        self::assertSame(40, $product->getStockLevel());
    }

    // ========================================================================
    // Sale Price Logic (from BasicProductTrait)
    // ========================================================================

    #[Test]
    public function is_on_sale_false_when_no_sale_price(): void
    {
        $product = self::createProduct(['price' => 29.99, 'salePrice' => null]);
        self::assertFalse($product->isOnSale());
    }

    #[Test]
    public function is_on_sale_true_when_sale_price_lower_than_price(): void
    {
        $product = self::createProduct(['price' => 29.99, 'salePrice' => 19.99]);
        self::assertTrue($product->isOnSale());
    }

    #[Test]
    public function is_on_sale_false_when_sale_price_equals_price(): void
    {
        // Edge case: sale price set but not actually a discount
        $product = self::createProduct(['price' => 29.99, 'salePrice' => 29.99]);
        self::assertFalse($product->isOnSale());
    }

    #[Test]
    public function is_on_sale_false_when_sale_price_higher_than_price(): void
    {
        // Edge case: misconfigured sale price
        $product = self::createProduct(['price' => 29.99, 'salePrice' => 39.99]);
        self::assertFalse($product->isOnSale());
    }

    #[Test]
    public function effective_price_returns_price_when_not_on_sale(): void
    {
        $product = self::createProduct(['price' => 29.99, 'salePrice' => null]);
        self::assertSame(29.99, $product->effectivePrice());
    }

    #[Test]
    public function effective_price_returns_sale_price_when_on_sale(): void
    {
        $product = self::createProduct(['price' => 29.99, 'salePrice' => 19.99]);
        self::assertSame(19.99, $product->effectivePrice());
    }

    #[Test]
    public function effective_price_returns_price_when_sale_price_not_lower(): void
    {
        $product = self::createProduct(['price' => 29.99, 'salePrice' => 39.99]);
        self::assertSame(29.99, $product->effectivePrice());
    }

    // ========================================================================
    // Image Methods
    // ========================================================================

    #[Test]
    public function primary_image_returns_null_when_no_images(): void
    {
        $product = self::createProduct();
        self::assertNull($product->primaryImage());
    }

    #[Test]
    public function primary_image_returns_first_image(): void
    {
        // Arrange
        $images = [
            new ProductImage(1, 'https://example.com/img1.jpg', 'Primary', 0),
            new ProductImage(2, 'https://example.com/img2.jpg', 'Secondary', 1),
        ];
        $product = self::createProduct(images: $images);

        // Act
        $primary = $product->primaryImage();

        // Assert
        self::assertNotNull($primary);
        self::assertSame(1, $primary->id);
        self::assertSame('https://example.com/img1.jpg', $primary->url);
    }

    // ========================================================================
    // allSkus()
    // ========================================================================

    #[Test]
    public function all_skus_returns_master_sku_only_for_simple_product(): void
    {
        $product = self::createProduct(['sku' => 'MASTER-001']);

        $skus = $product->allSkus();

        self::assertCount(1, $skus);
        self::assertSame('MASTER-001', $skus[0]->value);
    }

    #[Test]
    public function all_skus_returns_master_and_variation_skus(): void
    {
        $variations = [
            self::createVariation(1, 'VAR-001', 10),
            self::createVariation(2, 'VAR-002', 20),
        ];
        $product = self::createProduct(['sku' => 'MASTER-001'], variations: $variations);

        $skus = $product->allSkus();
        $values = \array_map(static fn(Sku $s): string => $s->value, $skus);

        self::assertSame(['MASTER-001', 'VAR-001', 'VAR-002'], $values);
    }

    #[Test]
    public function all_skus_excludes_null_master_sku(): void
    {
        $variations = [
            self::createVariation(1, 'VAR-001', 10),
        ];
        $product = self::createProduct(['sku' => null], variations: $variations);

        $skus = $product->allSkus();

        self::assertCount(1, $skus);
        self::assertSame('VAR-001', $skus[0]->value);
    }

    #[Test]
    public function all_skus_excludes_null_variation_skus(): void
    {
        $variationWithSku = self::createVariation(1, 'VAR-001', 10);
        $variationWithoutSku = new ProductVariation(
            id: 2,
            productExternalId: 12345,
            sku: null,
            price: 29.99,
            costPrice: null,
            salePrice: null,
            stock: 5,
            weight: null,
            gtin: null,
            mpn: null,
            imageIndex: null,
        );
        $product = self::createProduct(
            ['sku' => 'MASTER-001'],
            variations: [$variationWithSku, $variationWithoutSku],
        );

        $skus = $product->allSkus();
        $values = \array_map(static fn(Sku $s): string => $s->value, $skus);

        self::assertSame(['MASTER-001', 'VAR-001'], $values);
    }

    #[Test]
    public function all_skus_returns_empty_when_no_skus(): void
    {
        $product = self::createProduct(['sku' => null, 'variations' => null]);

        self::assertSame([], $product->allSkus());
    }

    #[Test]
    public function all_skus_returns_empty_for_empty_variations_and_null_sku(): void
    {
        $product = self::createProduct(['sku' => null, 'variations' => []]);

        self::assertSame([], $product->allSkus());
    }

    // ========================================================================
    // Interface Methods
    // ========================================================================

    #[Test]
    public function sku_method_returns_sku(): void
    {
        $product = self::createProduct(['sku' => 'ABC-123']);
        self::assertSame('ABC-123', $product->sku());
    }

    #[Test]
    public function price_method_returns_price(): void
    {
        $product = self::createProduct(['price' => 49.99]);
        self::assertSame(49.99, $product->price());
    }

    #[Test]
    public function cost_price_method_returns_cost_price(): void
    {
        $product = self::createProduct(['costPrice' => 25.00]);
        self::assertSame(25.00, $product->costPrice());
    }

    #[Test]
    public function sale_price_method_returns_sale_price(): void
    {
        $product = self::createProduct(['salePrice' => 19.99]);
        self::assertSame(19.99, $product->salePrice());
    }

    #[Test]
    public function weight_method_returns_weight(): void
    {
        $product = self::createProduct(['weight' => 1.5]);
        self::assertSame(1.5, $product->weight());
    }

    // ========================================================================
    // isSaleActive (static)
    // ========================================================================

    #[Test]
    #[DataProvider('saleActiveProvider')]
    public function is_sale_active_pins_each_branch(?float $salePrice, float $price, bool $expected): void
    {
        self::assertSame($expected, Product::isSaleActive($salePrice, $price));
    }

    /**
     * @return array<string, array{0: ?float, 1: float, 2: bool}>
     */
    public static function saleActiveProvider(): array
    {
        return [
            'null sale price' => [null, 29.99, false],
            'zero sale price' => [0.0, 29.99, false],
            'sale lower than price' => [19.99, 29.99, true],
            'sale equals price' => [29.99, 29.99, false],
            'sale higher than price' => [39.99, 29.99, false],
            'sale 0.01 below price' => [29.98, 29.99, true],
        ];
    }

    // ========================================================================
    // allOnSaleSkus
    // ========================================================================

    #[Test]
    public function all_on_sale_skus_returns_master_when_sale_active(): void
    {
        $product = self::createProduct([
            'sku' => 'MASTER-001',
            'price' => 29.99,
            'salePrice' => 19.99,
        ]);

        $skus = $product->allOnSaleSkus();
        $values = \array_map(static fn(Sku $s): string => $s->value, $skus);

        self::assertSame(['MASTER-001'], $values);
    }

    #[Test]
    public function all_on_sale_skus_excludes_master_when_sale_not_active(): void
    {
        $product = self::createProduct([
            'sku' => 'MASTER-001',
            'price' => 29.99,
            'salePrice' => null,
        ]);

        self::assertSame([], $product->allOnSaleSkus());
    }

    #[Test]
    public function all_on_sale_skus_excludes_master_with_empty_sku(): void
    {
        $product = self::createProduct([
            'sku' => '',
            'price' => 29.99,
            'salePrice' => 19.99,
        ]);

        self::assertSame([], $product->allOnSaleSkus());
    }

    #[Test]
    public function all_on_sale_skus_combines_master_and_variation_skus(): void
    {
        $variations = [
            self::createVariation(id: 1, sku: 'VAR-001', stock: 10, price: 29.99, salePrice: 19.99),
            self::createVariation(id: 2, sku: 'VAR-002', stock: 10, price: 29.99, salePrice: null),
        ];
        $product = self::createProduct(
            ['sku' => 'MASTER-001', 'price' => 29.99, 'salePrice' => 19.99],
            variations: $variations,
        );

        $values = \array_map(static fn(Sku $s): string => $s->value, $product->allOnSaleSkus());

        self::assertSame(['MASTER-001', 'VAR-001'], $values);
    }

    #[Test]
    public function all_on_sale_skus_handles_null_variations(): void
    {
        $product = self::createProduct([
            'sku' => 'MASTER-001',
            'price' => 29.99,
            'salePrice' => 19.99,
            'variations' => null,
        ]);

        self::assertSame(['MASTER-001'], \array_map(static fn(Sku $s): string => $s->value, $product->allOnSaleSkus()));
    }

    #[Test]
    public function all_on_sale_skus_returns_only_variations_when_master_sku_null(): void
    {
        $variations = [
            self::createVariation(id: 1, sku: 'VAR-001', stock: 10, price: 29.99, salePrice: 19.99),
        ];
        $product = self::createProduct(['sku' => null], variations: $variations);

        $values = \array_map(static fn(Sku $s): string => $s->value, $product->allOnSaleSkus());

        self::assertSame(['VAR-001'], $values);
    }

    // ========================================================================
    // getFilter
    // ========================================================================

    #[Test]
    public function get_filter_returns_matching_filter_by_title(): void
    {
        $sizeFilter = new ProductFilter(
            new FilterGroupDefinition(id: 1, title: 'Size', optionNo: 1, sortOrder: 0),
            ['Small', 'Large'],
        );
        $colourFilter = new ProductFilter(
            new FilterGroupDefinition(id: 2, title: 'Colour', optionNo: 2, sortOrder: 1),
            ['Red'],
        );
        $product = self::createProduct(['filters' => [$sizeFilter, $colourFilter]]);

        $found = $product->getFilter('Colour');

        self::assertSame($colourFilter, $found);
    }

    #[Test]
    public function get_filter_returns_null_when_title_does_not_match(): void
    {
        $sizeFilter = new ProductFilter(
            new FilterGroupDefinition(id: 1, title: 'Size', optionNo: 1, sortOrder: 0),
            ['Small'],
        );
        $product = self::createProduct(['filters' => [$sizeFilter]]);

        self::assertNull($product->getFilter('Missing'));
    }

    #[Test]
    public function get_filter_returns_null_when_no_filters(): void
    {
        $product = self::createProduct(['filters' => []]);

        self::assertNull($product->getFilter('Size'));
    }
}
