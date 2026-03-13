<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Responses;

use App\Infrastructure\Shopwired\Responses\ProductWebhookResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\Optional;
use Tests\TestCase;

/**
 * ProductWebhookResponse Unit Tests.
 *
 * Tests the presentEmbeds() detection logic and getCategoryIds() handling
 * of Optional vs present categories.
 */
#[CoversClass(ProductWebhookResponse::class)]
final class ProductWebhookResponseTest extends TestCase
{
    // ========================================================================
    // presentEmbeds()
    // ========================================================================

    #[Test]
    public function it_returns_empty_embeds_when_none_present(): void
    {
        $response = ProductWebhookResponse::from(self::corePayload());

        self::assertSame([], $response->presentEmbeds());
    }

    #[Test]
    public function it_detects_vat_relief_when_present(): void
    {
        $payload = self::corePayload();
        $payload['vat_relief'] = true;

        $response = ProductWebhookResponse::from($payload);

        self::assertSame(['vat_relief'], $response->presentEmbeds());
    }

    #[Test]
    public function it_detects_vat_relief_false_as_present(): void
    {
        $payload = self::corePayload();
        $payload['vat_relief'] = false;

        $response = ProductWebhookResponse::from($payload);

        self::assertContains('vat_relief', $response->presentEmbeds());
    }

    #[Test]
    public function it_detects_all_embeds_when_all_present(): void
    {
        $payload = self::corePayload();
        $payload['vat_relief'] = true;
        $payload['variations'] = [];
        $payload['images'] = [];
        $payload['categories'] = [];
        $payload['custom_fields'] = [];
        $payload['filters'] = [];

        $response = ProductWebhookResponse::from($payload);

        $expected = ['vat_relief', 'variations', 'images', 'categories', 'custom_fields', 'filters'];
        self::assertSame($expected, $response->presentEmbeds());
    }

    #[Test]
    public function it_detects_partial_embeds(): void
    {
        $payload = self::corePayload();
        $payload['images'] = [['id' => 1, 'url' => 'https://img.example.com/1.jpg', 'description' => null, 'sort_order' => 0]];
        $payload['filters'] = ['1' => ['Small']];

        $response = ProductWebhookResponse::from($payload);

        self::assertSame(['images', 'filters'], $response->presentEmbeds());
    }

    // ========================================================================
    // getCategoryIds()
    // ========================================================================

    #[Test]
    public function it_returns_empty_category_ids_when_categories_optional(): void
    {
        $response = ProductWebhookResponse::from(self::corePayload());

        self::assertInstanceOf(Optional::class, $response->categories);
        self::assertSame([], $response->getCategoryIds());
    }

    #[Test]
    public function it_extracts_category_ids_when_present(): void
    {
        $payload = self::corePayload();
        $payload['categories'] = [
            ['id' => 10, 'title' => 'Electronics'],
            ['id' => 20, 'title' => 'Accessories'],
        ];

        $response = ProductWebhookResponse::from($payload);

        self::assertSame([10, 20], $response->getCategoryIds());
    }

    // ========================================================================
    // Fixtures
    // ========================================================================

    /**
     * @return array<string, mixed>
     */
    private static function corePayload(): array
    {
        return [
            'id' => 1,
            'sku' => 'SKU-1',
            'gtin' => null,
            'title' => 'Product',
            'description' => null,
            'slug' => 'product',
            'url' => 'https://shop.example.com/product',
            'price' => 9.99,
            'cost_price' => null,
            'sale_price' => null,
            'compare_price' => null,
            'stock' => 10,
            'active' => true,
            'vat_exclusive' => false,
            'weight' => null,
            'meta_title' => null,
            'meta_description' => null,
            'created_at' => '2025-01-01T00:00:00Z',
            'updated_at' => '2025-01-01T00:00:00Z',
        ];
    }
}
