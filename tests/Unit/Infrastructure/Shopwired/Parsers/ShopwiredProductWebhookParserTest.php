<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Parsers;

use App\Application\Shopwired\DTOs\StockChangeDTO;
use App\Application\Shopwired\DTOs\WebhookProductResultDTO;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Infrastructure\Shopwired\Factories\ProductDomainFactory;
use App\Infrastructure\Shopwired\Parsers\ShopwiredProductWebhookParser;
use Illuminate\Support\Facades\Log;
use Mockery;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ShopwiredProductWebhookParser Unit Tests.
 *
 * Tests payload parsing, missing key guards, and exception catch coverage
 * for both parseProduct() and parseStockChange().
 */
#[CoversClass(ShopwiredProductWebhookParser::class)]
final class ShopwiredProductWebhookParserTest extends TestCase
{
    private ShopwiredProductWebhookParser $parser;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new ShopwiredProductWebhookParser(
            new ProductDomainFactory(),
        );
    }

    // ========================================================================
    // parseProduct — Happy Path
    // ========================================================================

    #[Test]
    public function it_parses_a_valid_product_webhook_payload(): void
    {
        $data = ['object' => self::validProductPayload()];

        $result = $this->parser->parseProduct($data);

        self::assertInstanceOf(WebhookProductResultDTO::class, $result);
        self::assertSame(12345, $result->product->id);
        self::assertSame('Test Product', $result->product->title);
        self::assertSame('test-product', $result->product->slug);
        self::assertSame(29.99, $result->product->price);
        self::assertSame(true, $result->product->isActive);
        self::assertSame([], $result->presentEmbeds);
    }

    #[Test]
    public function it_detects_present_embeds_in_webhook_payload(): void
    {
        $payload = self::validProductPayload();
        $payload['vat_relief'] = true;
        $payload['categories'] = [['id' => 1, 'title' => 'Cat']];
        $data = ['object' => $payload];

        $result = $this->parser->parseProduct($data);

        self::assertContains('vat_relief', $result->presentEmbeds);
        self::assertContains('categories', $result->presentEmbeds);
        self::assertNotContains('variations', $result->presentEmbeds);
        self::assertNotContains('images', $result->presentEmbeds);
        self::assertNotContains('custom_fields', $result->presentEmbeds);
        self::assertNotContains('filters', $result->presentEmbeds);
    }

    // ========================================================================
    // parseProduct — Error Paths
    // ========================================================================

    #[Test]
    public function it_throws_when_object_key_is_missing(): void
    {
        $this->expectException(InvalidApiResponseException::class);

        $this->parser->parseProduct(['event' => 'product.updated']);
    }

    #[Test]
    public function it_throws_on_invalid_product_data_type(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with('ShopWired product webhook payload type mismatch', Mockery::type('array'));

        $this->expectException(InvalidApiResponseException::class);

        // Missing required fields — Spatie will throw CannotCreateData
        $this->parser->parseProduct(['object' => ['id' => 'not-an-int']]);
    }

    // ========================================================================
    // parseStockChange — Happy Path
    // ========================================================================

    #[Test]
    public function it_parses_a_valid_stock_change_payload(): void
    {
        $data = [
            'sku' => 'ABC-123',
            'is_variation' => true,
            'new_quantity' => 42,
        ];

        $result = $this->parser->parseStockChange($data);

        self::assertInstanceOf(StockChangeDTO::class, $result);
        self::assertSame('ABC-123', $result->sku);
        self::assertTrue($result->isVariation);
        self::assertSame(42, $result->newQuantity);
    }

    // ========================================================================
    // parseStockChange — Error Path
    // ========================================================================

    #[Test]
    public function it_throws_on_invalid_stock_change_data(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with('ShopWired product stock webhook payload type mismatch', Mockery::type('array'));

        $this->expectException(InvalidApiResponseException::class);

        // Missing required 'sku' field
        $this->parser->parseStockChange(['is_variation' => false, 'new_quantity' => 10]);
    }

    // ========================================================================
    // Fixtures
    // ========================================================================

    /**
     * Minimal valid product webhook payload (core fields only, no embeds).
     *
     * @return array<string, mixed>
     */
    private static function validProductPayload(): array
    {
        return [
            'id' => 12345,
            'sku' => 'TEST-SKU',
            'gtin' => null,
            'title' => 'Test Product',
            'description' => '<p>Description</p>',
            'slug' => 'test-product',
            'url' => 'https://shop.example.com/test-product',
            'price' => 29.99,
            'cost_price' => 10.00,
            'sale_price' => null,
            'compare_price' => null,
            'stock' => 100,
            'active' => true,
            'vat_exclusive' => false,
            'weight' => 0.5,
            'meta_title' => null,
            'meta_description' => null,
            'created_at' => '2025-01-01T00:00:00Z',
            'updated_at' => '2025-06-15T12:00:00Z',
        ];
    }
}
