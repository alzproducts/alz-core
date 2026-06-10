<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Transformers;

use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldValueList;
use App\Domain\Catalog\Product\Transformers\ProductRetailPricingTransformer;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductRetailPricingTransformer::class)]
final class ProductRetailPricingTransformerTest extends TestCase
{
    #[Test]
    public function master_product_only_with_no_sale(): void
    {
        $product = self::createProduct('MASTER-001', 29.99, null);

        $map = ProductRetailPricingTransformer::fromProduct($product);

        self::assertCount(1, $map);
        self::assertArrayHasKey('MASTER-001', $map);
        self::assertSame(29.99, $map['MASTER-001']->basePrice->toGross());
        self::assertNull($map['MASTER-001']->salePrice);
    }

    #[Test]
    public function master_product_with_sale(): void
    {
        $product = self::createProduct('MASTER-001', 29.99, 19.99);

        $map = ProductRetailPricingTransformer::fromProduct($product);

        self::assertSame(29.99, $map['MASTER-001']->basePrice->toGross());
        self::assertSame(19.99, $map['MASTER-001']->salePrice->toGross());
    }

    #[Test]
    public function master_with_variations(): void
    {
        $product = self::createProduct('MASTER-001', 20.00, null, [
            self::createVariation(1, 'VAR-001', 25.00, null),
            self::createVariation(2, 'VAR-002', null, 15.00),
        ]);

        $map = ProductRetailPricingTransformer::fromProduct($product);

        self::assertCount(3, $map);

        // Master
        self::assertSame(20.0, $map['MASTER-001']->basePrice->toGross());

        // VAR-001: own price, no sale
        self::assertSame(25.0, $map['VAR-001']->basePrice->toGross());
        self::assertNull($map['VAR-001']->salePrice);

        // VAR-002: inherits parent price (null variation price), has sale
        self::assertSame(20.0, $map['VAR-002']->basePrice->toGross());
        self::assertSame(15.0, $map['VAR-002']->salePrice->toGross());
    }

    #[Test]
    public function skips_variations_with_null_sku(): void
    {
        $product = self::createProduct('MASTER-001', 20.00, null, [
            self::createVariation(1, 'VAR-001', 25.00, null),
            self::createVariation(2, null, 15.00, null),
        ]);

        $map = ProductRetailPricingTransformer::fromProduct($product);

        self::assertCount(2, $map);
        self::assertArrayHasKey('MASTER-001', $map);
        self::assertArrayHasKey('VAR-001', $map);
    }

    #[Test]
    public function skips_variations_with_empty_sku(): void
    {
        $product = self::createProduct('MASTER-001', 20.00, null, [
            self::createVariation(1, '', 25.00, null),
        ]);

        $map = ProductRetailPricingTransformer::fromProduct($product);

        self::assertCount(1, $map);
        self::assertArrayHasKey('MASTER-001', $map);
    }

    // ========================================================================
    // RRP mapping
    // ========================================================================

    #[Test]
    public function master_product_compare_price_mapped_to_rrp(): void
    {
        $product = self::createProduct('MASTER-001', 29.99, null, comparePrice: 39.99);

        $map = ProductRetailPricingTransformer::fromProduct($product);

        self::assertSame(39.99, $map['MASTER-001']->rrp->toGross());
    }

    #[Test]
    public function variation_rrp_is_always_null_regardless_of_master(): void
    {
        $product = self::createProduct('MASTER-001', 29.99, null, comparePrice: 39.99, variations: [
            self::createVariation(1, 'VAR-001', 25.00, null),
        ]);

        $map = ProductRetailPricingTransformer::fromProduct($product);

        self::assertNull($map['VAR-001']->rrp);
    }

    // ========================================================================
    // Factory Helpers
    // ========================================================================

    /**
     * @param list<ProductVariation> $variations
     */
    private static function createProduct(
        string $masterSku,
        float $price,
        ?float $salePrice,
        array $variations = [],
        ?float $comparePrice = null,
    ): Product {
        return new Product(
            id: 1,
            sku: $masterSku,
            gtin: null,
            title: 'Test Product',
            description: null,
            slug: 'test-product',
            url: 'https://example.com/test',
            price: $price,
            costPrice: null,
            salePrice: $salePrice,
            comparePrice: $comparePrice,
            stock: 100,
            isActive: true,
            vatExclusive: false,
            vatRelief: false,
            weight: null,
            metaTitle: null,
            metaDescription: null,
            categoryIds: [],
            variations: $variations,
            images: [],
            rawCustomFields: [],
            customFields: CustomFieldValueList::empty(),
            rawFilters: [],
            filters: [],
            sortOrder: null,
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
        );
    }

    private static function createVariation(
        int $id,
        ?string $sku,
        ?float $price,
        ?float $salePrice,
    ): ProductVariation {
        return new ProductVariation(
            id: $id,
            productExternalId: 1,
            sku: $sku,
            price: $price,
            costPrice: null,
            salePrice: $salePrice,
            stock: 50,
            weight: null,
            gtin: null,
            mpn: null,
            imageIndex: null,
        );
    }
}
