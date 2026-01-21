<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\Exceptions\MissingVariationSkuException;
use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationOption;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ProductVariation value object validation and business logic.
 *
 * Key business rule: All purchasable variants MUST have an SKU for inventory
 * tracking and order fulfillment. Missing SKU is a data quality issue in
 * ShopWired that must be fixed by the user.
 */
#[CoversClass(ProductVariation::class)]
final class ProductVariationTest extends TestCase
{
    // ========================================================================
    // Factory Helper
    // ========================================================================

    /**
     * Create a variation with sensible defaults for testing.
     *
     * @param array<string, mixed> $overrides
     * @param list<ProductVariationOption> $options
     */
    private static function createVariation(
        array $overrides = [],
        array $options = [],
    ): ProductVariation {
        $defaults = [
            'id' => 1001,
            'productExternalId' => 12345,
            'sku' => 'VAR-SKU-001',
            'price' => 29.99,
            'costPrice' => 15.00,
            'salePrice' => null,
            'stock' => 50,
            'weight' => 0.5,
            'gtin' => null,
            'mpn' => null,
            'imageIndex' => null,
            'options' => $options,
        ];

        $data = [...$defaults, ...$overrides];

        return new ProductVariation(
            id: $data['id'],
            productExternalId: $data['productExternalId'],
            sku: $data['sku'],
            price: $data['price'],
            costPrice: $data['costPrice'],
            salePrice: $data['salePrice'],
            stock: $data['stock'],
            weight: $data['weight'],
            gtin: $data['gtin'],
            mpn: $data['mpn'],
            imageIndex: $data['imageIndex'],
            options: $data['options'],
        );
    }

    // ========================================================================
    // Happy Path
    // ========================================================================

    #[Test]
    public function it_creates_valid_variation(): void
    {
        // Act
        $variation = self::createVariation();

        // Assert
        self::assertSame(1001, $variation->id);
        self::assertSame(12345, $variation->productExternalId);
        self::assertSame('VAR-SKU-001', $variation->sku);
        self::assertSame(29.99, $variation->price);
        self::assertSame(50, $variation->stock);
    }

    #[Test]
    public function it_creates_variation_with_all_optional_fields(): void
    {
        // Arrange
        $gtin = Gtin::fromTrusted('9780201633610');
        $options = [
            new ProductVariationOption(1, 'Size', 10, 'Large'),
            new ProductVariationOption(2, 'Color', 20, 'Red'),
        ];

        // Act
        $variation = self::createVariation([
            'gtin' => $gtin,
            'mpn' => 'MPN-12345',
            'imageIndex' => 2,
        ], options: $options);

        // Assert
        self::assertSame('9780201633610', $variation->gtin?->value);
        self::assertSame('MPN-12345', $variation->mpn);
        self::assertSame(2, $variation->imageIndex);
        self::assertCount(2, $variation->options);
    }

    // ========================================================================
    // SKU Requirement - Business Rule
    // ========================================================================

    #[Test]
    public function it_throws_missing_sku_exception_for_null_sku(): void
    {
        // Assert
        $this->expectException(MissingVariationSkuException::class);
        $this->expectExceptionMessage('Product variation 1001 (parent product 12345) is missing required SKU');

        // Act
        self::createVariation(['sku' => null]);
    }

    #[Test]
    public function it_throws_missing_sku_exception_for_empty_sku(): void
    {
        // Assert
        $this->expectException(MissingVariationSkuException::class);

        // Act
        self::createVariation(['sku' => '']);
    }

    #[Test]
    public function it_throws_missing_sku_exception_for_whitespace_only_sku(): void
    {
        // SKU is trimmed before validation, so whitespace-only is treated as empty

        // Assert
        $this->expectException(MissingVariationSkuException::class);

        // Act
        self::createVariation(['sku' => '   ']);
    }

    #[Test]
    public function it_trims_sku_whitespace(): void
    {
        // Act
        $variation = self::createVariation(['sku' => '  ABC-123  ']);

        // Assert - SKU should be trimmed
        self::assertSame('ABC-123', $variation->sku);
    }

    #[Test]
    public function missing_sku_exception_contains_context(): void
    {
        // Arrange
        $variationId = 9999;
        $productExternalId = 88888;

        try {
            new ProductVariation(
                id: $variationId,
                productExternalId: $productExternalId,
                sku: null,
                price: 10.00,
                costPrice: null,
                salePrice: null,
                stock: 0,
                weight: null,
                gtin: null,
                mpn: null,
                imageIndex: null,
            );
            self::fail('Expected MissingVariationSkuException');
        } catch (MissingVariationSkuException $e) {
            // Assert - exception should contain IDs for debugging
            self::assertSame($variationId, $e->variationId);
            self::assertSame($productExternalId, $e->productExternalId);
            self::assertStringContainsString('9999', $e->getMessage());
            self::assertStringContainsString('88888', $e->getMessage());
        }
    }

    // ========================================================================
    // Constructor Validation
    // ========================================================================

    #[Test]
    public function it_rejects_non_positive_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Variation ID must be positive');

        self::createVariation(['id' => 0]);
    }

    #[Test]
    public function it_rejects_non_positive_product_external_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Product external ID must be positive');

        self::createVariation(['productExternalId' => 0]);
    }

    #[Test]
    public function it_rejects_negative_price(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Price cannot be negative');

        self::createVariation(['price' => -0.01]);
    }

    #[Test]
    public function it_allows_zero_price(): void
    {
        // Act
        $variation = self::createVariation(['price' => 0.0]);

        // Assert
        self::assertSame(0.0, $variation->price);
    }

    #[Test]
    public function it_allows_negative_stock_for_backorders(): void
    {
        // ShopWired supports backorders

        // Act
        $variation = self::createVariation(['stock' => -10]);

        // Assert
        self::assertSame(-10, $variation->stock);
    }

    // ========================================================================
    // Stock Methods
    // ========================================================================

    #[Test]
    public function is_in_stock_true_when_positive_stock(): void
    {
        $variation = self::createVariation(['stock' => 1]);
        self::assertTrue($variation->isInStock());
    }

    #[Test]
    public function is_in_stock_false_when_zero_stock(): void
    {
        $variation = self::createVariation(['stock' => 0]);
        self::assertFalse($variation->isInStock());
    }

    #[Test]
    public function is_in_stock_false_when_negative_stock(): void
    {
        $variation = self::createVariation(['stock' => -5]);
        self::assertFalse($variation->isInStock());
    }

    #[Test]
    public function get_stock_level_returns_stock(): void
    {
        $variation = self::createVariation(['stock' => 75]);
        self::assertSame(75, $variation->getStockLevel());
    }

    // ========================================================================
    // Sale Price Logic - REMOVED
    // isOnSale() and effectivePrice() removed from ProductVariation because
    // it no longer implements BasicProductInterface. Nullable price semantics
    // (null = inherit parent, 0.00 = removed from sale) require parent context.
    // See: .ai/docs/known-issues.md "BasicProductInterface and ProductVariation"
    // ========================================================================

    // ========================================================================
    // Options Display
    // ========================================================================

    #[Test]
    public function options_display_string_returns_empty_for_no_options(): void
    {
        $variation = self::createVariation();
        self::assertSame('', $variation->optionsDisplayString());
    }

    #[Test]
    public function options_display_string_formats_single_option(): void
    {
        $options = [
            new ProductVariationOption(1, 'Size', 10, 'Large'),
        ];
        $variation = self::createVariation(options: $options);

        self::assertSame('Size: Large', $variation->optionsDisplayString());
    }

    #[Test]
    public function options_display_string_formats_multiple_options(): void
    {
        $options = [
            new ProductVariationOption(1, 'Size', 10, 'Large'),
            new ProductVariationOption(2, 'Color', 20, 'Red'),
        ];
        $variation = self::createVariation(options: $options);

        self::assertSame('Size: Large, Color: Red', $variation->optionsDisplayString());
    }

    // ========================================================================
    // Interface Methods
    // ========================================================================

    #[Test]
    public function sku_method_returns_sku(): void
    {
        $variation = self::createVariation(['sku' => 'TEST-SKU']);
        self::assertSame('TEST-SKU', $variation->sku());
    }

    #[Test]
    public function price_method_returns_price(): void
    {
        $variation = self::createVariation(['price' => 49.99]);
        self::assertSame(49.99, $variation->price());
    }

    #[Test]
    public function cost_price_method_returns_cost_price(): void
    {
        $variation = self::createVariation(['costPrice' => 25.00]);
        self::assertSame(25.00, $variation->costPrice());
    }

    #[Test]
    public function sale_price_method_returns_sale_price(): void
    {
        $variation = self::createVariation(['salePrice' => 19.99]);
        self::assertSame(19.99, $variation->salePrice());
    }

    #[Test]
    public function weight_method_returns_weight(): void
    {
        $variation = self::createVariation(['weight' => 1.5]);
        self::assertSame(1.5, $variation->weight());
    }
}
