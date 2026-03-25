<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Mappers;

use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductImage;
use App\Infrastructure\Catalog\Product\Mappers\ProductModelMapper;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ProductModelMapper Webhook Attribute Tests.
 *
 * Tests the conditional column inclusion in toWebhookAttributes(),
 * verifying that only embed-dependent columns present in the webhook
 * payload are included in the output.
 */
#[CoversClass(ProductModelMapper::class)]
final class ProductModelMapperWebhookTest extends TestCase
{
    // ========================================================================
    // toWebhookAttributes — Conditional Embed Columns
    // ========================================================================

    #[Test]
    public function it_includes_only_core_attributes_when_no_embeds_present(): void
    {
        $product = self::buildProduct();

        $attributes = ProductModelMapper::toWebhookAttributes($product, []);

        // Core fields should be present
        self::assertSame(12345, $attributes['external_id']);
        self::assertSame('TEST-SKU', $attributes['sku']);
        self::assertSame('Test Product', $attributes['title']);
        self::assertSame(29.99, $attributes['price']);
        self::assertTrue($attributes['is_active']);

        // Embed-dependent fields should NOT be present
        self::assertArrayNotHasKey('vat_relief', $attributes);
        self::assertArrayNotHasKey('category_ids', $attributes);
        self::assertArrayNotHasKey('images', $attributes);
        self::assertArrayNotHasKey('custom_fields', $attributes);
        self::assertArrayNotHasKey('filters', $attributes);
    }

    #[Test]
    public function it_includes_vat_relief_when_present_in_embeds(): void
    {
        $product = self::buildProduct(vatRelief: true);

        $attributes = ProductModelMapper::toWebhookAttributes($product, ['vat_relief']);

        self::assertTrue($attributes['vat_relief']);
        self::assertArrayNotHasKey('category_ids', $attributes);
        self::assertArrayNotHasKey('images', $attributes);
    }

    #[Test]
    public function it_includes_categories_when_present_in_embeds(): void
    {
        $product = self::buildProduct(categoryIds: [10, 20, 30]);

        $attributes = ProductModelMapper::toWebhookAttributes($product, ['categories']);

        self::assertSame([10, 20, 30], $attributes['category_ids']);
        self::assertArrayNotHasKey('vat_relief', $attributes);
    }

    #[Test]
    public function it_includes_images_when_present_in_embeds(): void
    {
        $image = ProductImage::fromArray([
            'id' => 1,
            'url' => 'https://img.example.com/1.jpg',
            'description' => null,
            'sort_order' => 0,
        ]);
        $product = self::buildProduct(images: [$image]);

        $attributes = ProductModelMapper::toWebhookAttributes($product, ['images']);

        self::assertCount(1, $attributes['images']);
        self::assertSame(1, $attributes['images'][0]['id']);
    }

    #[Test]
    public function it_includes_custom_fields_and_filters_when_present(): void
    {
        $product = self::buildProduct(
            rawCustomFields: ['colour' => 'Red'],
            rawFilters: [1 => ['Small', 'Medium']],
        );

        $attributes = ProductModelMapper::toWebhookAttributes($product, ['custom_fields', 'filters']);

        self::assertSame(['colour' => 'Red'], $attributes['custom_fields']);
        self::assertSame([1 => ['Small', 'Medium']], $attributes['filters']);
        self::assertArrayNotHasKey('vat_relief', $attributes);
        self::assertArrayNotHasKey('category_ids', $attributes);
        self::assertArrayNotHasKey('images', $attributes);
    }

    #[Test]
    public function it_includes_all_columns_when_all_embeds_present(): void
    {
        $product = self::buildProduct();
        $allEmbeds = ['vat_relief', 'categories', 'images', 'custom_fields', 'filters'];

        $webhookAttributes = ProductModelMapper::toWebhookAttributes($product, $allEmbeds);
        $fullAttributes = ProductModelMapper::toModelAttributes($product);

        // Both should have the same keys (webhook with all embeds = full save)
        self::assertSame(
            \array_keys($fullAttributes),
            \array_keys($webhookAttributes),
            'Webhook attributes with all embeds should have the same keys as full model attributes',
        );
    }

    // ========================================================================
    // Fixtures
    // ========================================================================

    /**
     * @param list<int> $categoryIds
     * @param list<ProductImage> $images
     * @param array<string, mixed> $rawCustomFields
     * @param array<int|string, list<string>> $rawFilters
     */
    private static function buildProduct(
        bool $vatRelief = false,
        array $categoryIds = [],
        array $images = [],
        array $rawCustomFields = [],
        array $rawFilters = [],
    ): Product {
        return new Product(
            id: 12345,
            sku: 'TEST-SKU',
            gtin: null,
            title: 'Test Product',
            description: '<p>Description</p>',
            slug: 'test-product',
            url: 'https://shop.example.com/test-product',
            price: 29.99,
            costPrice: 10.00,
            salePrice: null,
            comparePrice: null,
            stock: 100,
            isActive: true,
            vatExclusive: false,
            vatRelief: $vatRelief,
            weight: 0.5,
            metaTitle: null,
            metaDescription: null,
            categoryIds: $categoryIds,
            variations: [],
            images: $images,
            rawCustomFields: $rawCustomFields,
            customFields: [],
            rawFilters: $rawFilters,
            filters: [],
            sortOrder: null,
            createdAt: new DateTimeImmutable('2025-01-01'),
            updatedAt: new DateTimeImmutable('2025-06-15'),
        );
    }
}
