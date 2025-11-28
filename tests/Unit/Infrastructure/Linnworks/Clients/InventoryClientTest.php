<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks\Clients;

use App\Domain\Exceptions\ResourceNotFoundException;
use App\Domain\Inventory\ValueObjects\StockItem;
use App\Infrastructure\Linnworks\Clients\InventoryClient;
use App\Infrastructure\Linnworks\LinnworksHttpTransport;
use Illuminate\Http\Client\Response;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * InventoryClient Unit Tests.
 *
 * Tests the Linnworks inventory API client:
 * - SKU to StockItemId resolution (two-step lookup)
 * - Stock item retrieval by ID
 * - Response parsing via LinnworksResponseParserTrait
 * - ResourceNotFoundException for missing items
 * - Domain conversion (StockItemResponse → StockItem)
 */
#[CoversClass(InventoryClient::class)]
final class InventoryClientTest extends TestCase
{
    private const string TEST_SKU = 'ABC-123';
    private const string TEST_STOCK_ITEM_ID = 'guid-stock-item-id-12345';

    private MockInterface&LinnworksHttpTransport $transport;
    private InventoryClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = Mockery::mock(LinnworksHttpTransport::class);
        $this->client = new InventoryClient($this->transport);
    }

    /*
    |--------------------------------------------------------------------------
    | Successful Retrieval Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_stock_item_for_valid_sku(): void
    {
        $this->mockSkuToStockIdMapping([
            [
                'StockItemId' => self::TEST_STOCK_ITEM_ID,
                'SKU' => self::TEST_SKU,
            ],
        ]);

        $this->mockStockItemById(self::validStockItemResponse());

        $result = $this->client->getStockItemBySku(self::TEST_SKU);

        $this->assertInstanceOf(StockItem::class, $result);
        $this->assertSame(self::TEST_SKU, $result->sku);
        $this->assertSame('Test Product Title', $result->title);
        $this->assertSame('1234567890', $result->barcode);
        $this->assertSame(100, $result->quantity);
        $this->assertSame(95, $result->available);
    }

    #[Test]
    public function it_maps_all_stock_item_fields_correctly(): void
    {
        $this->mockSkuToStockIdMapping([
            [
                'StockItemId' => self::TEST_STOCK_ITEM_ID,
                'SKU' => self::TEST_SKU,
            ],
        ]);

        $this->mockStockItemById(self::validStockItemResponse());

        $result = $this->client->getStockItemBySku(self::TEST_SKU);

        // Inventory quantities
        $this->assertSame(100, $result->quantity);
        $this->assertSame(95, $result->available);
        $this->assertSame(5, $result->inOrder);
        $this->assertSame(10, $result->due);
        $this->assertSame(20, $result->minimumLevel);

        // Pricing
        $this->assertSame(25.50, $result->purchasePrice);
        $this->assertSame(49.99, $result->retailPrice);
        $this->assertSame(20.0, $result->taxRate);

        // Dimensions
        $this->assertSame(1.5, $result->weight);
        $this->assertSame(10.0, $result->height);
        $this->assertSame(5.0, $result->width);
        $this->assertSame(2.0, $result->depth);

        // Flags
        $this->assertFalse($result->isComposite);
        $this->assertNull($result->description); // Not returned by this endpoint
    }

    #[Test]
    public function it_handles_null_weight_correctly(): void
    {
        $this->mockSkuToStockIdMapping([
            [
                'StockItemId' => self::TEST_STOCK_ITEM_ID,
                'SKU' => self::TEST_SKU,
            ],
        ]);

        $response = self::validStockItemResponse();
        $response['Weight'] = null;
        $this->mockStockItemById($response);

        $result = $this->client->getStockItemBySku(self::TEST_SKU);

        $this->assertNull($result->weight);
    }

    #[Test]
    public function it_handles_composite_parent_true(): void
    {
        $this->mockSkuToStockIdMapping([
            [
                'StockItemId' => self::TEST_STOCK_ITEM_ID,
                'SKU' => self::TEST_SKU,
            ],
        ]);

        $response = self::validStockItemResponse();
        $response['IsCompositeParent'] = true;
        $this->mockStockItemById($response);

        $result = $this->client->getStockItemBySku(self::TEST_SKU);

        $this->assertTrue($result->isComposite);
    }

    #[Test]
    public function it_handles_null_composite_parent_as_false(): void
    {
        $this->mockSkuToStockIdMapping([
            [
                'StockItemId' => self::TEST_STOCK_ITEM_ID,
                'SKU' => self::TEST_SKU,
            ],
        ]);

        $response = self::validStockItemResponse();
        $response['IsCompositeParent'] = null;
        $this->mockStockItemById($response);

        $result = $this->client->getStockItemBySku(self::TEST_SKU);

        $this->assertFalse($result->isComposite);
    }

    /*
    |--------------------------------------------------------------------------
    | Resource Not Found Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_resource_not_found_when_sku_not_in_mappings(): void
    {
        // Return empty mappings array
        $this->mockSkuToStockIdMapping([]);

        $this->expectException(ResourceNotFoundException::class);

        $this->client->getStockItemBySku(self::TEST_SKU);
    }

    #[Test]
    public function it_throws_resource_not_found_when_sku_not_matched(): void
    {
        // Return mappings for different SKU
        $this->mockSkuToStockIdMapping([
            [
                'StockItemId' => 'other-id',
                'SKU' => 'OTHER-SKU',
            ],
        ]);

        try {
            $this->client->getStockItemBySku(self::TEST_SKU);
            $this->fail('Expected ResourceNotFoundException');
        } catch (ResourceNotFoundException $e) {
            $this->assertSame('Linnworks', $e->serviceName);
            $this->assertSame('StockItem', $e->resourceType);
            $this->assertSame(self::TEST_SKU, $e->resourceId);
        }
    }

    #[Test]
    public function it_throws_resource_not_found_when_stock_item_returns_null(): void
    {
        $this->mockSkuToStockIdMapping([
            [
                'StockItemId' => self::TEST_STOCK_ITEM_ID,
                'SKU' => self::TEST_SKU,
            ],
        ]);

        // Stock item endpoint returns null
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->andReturn(null);

        $this->transport->shouldReceive('get')
            ->with('/api/Inventory/GetInventoryItemById', ['id' => self::TEST_STOCK_ITEM_ID])
            ->andReturn($response);

        try {
            $this->client->getStockItemBySku(self::TEST_SKU);
            $this->fail('Expected ResourceNotFoundException');
        } catch (ResourceNotFoundException $e) {
            $this->assertSame('Linnworks', $e->serviceName);
            $this->assertSame('StockItem', $e->resourceType);
            $this->assertSame(self::TEST_STOCK_ITEM_ID, $e->resourceId);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Request Format Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_posts_sku_in_correct_format_for_id_lookup(): void
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->andReturn(['Items' => []]);

        $this->transport->shouldReceive('post')
            ->once()
            ->with(
                '/api/Inventory/GetStockItemIdsBySKU',
                ['SKUS' => [self::TEST_SKU]],
            )
            ->andReturn($response);

        try {
            $this->client->getStockItemBySku(self::TEST_SKU);
        } catch (ResourceNotFoundException) {
            // Expected - we're testing request format only
        }
    }

    #[Test]
    public function it_sends_stock_item_id_in_query_for_item_lookup(): void
    {
        $this->mockSkuToStockIdMapping([
            [
                'StockItemId' => self::TEST_STOCK_ITEM_ID,
                'SKU' => self::TEST_SKU,
            ],
        ]);

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->andReturn(self::validStockItemResponse());

        $this->transport->shouldReceive('get')
            ->once()
            ->with('/api/Inventory/GetInventoryItemById', ['id' => self::TEST_STOCK_ITEM_ID])
            ->andReturn($response);

        $this->client->getStockItemBySku(self::TEST_SKU);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function mockSkuToStockIdMapping(array $mappings): void
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->andReturn(['Items' => $mappings]);

        $this->transport->shouldReceive('post')
            ->with('/api/Inventory/GetStockItemIdsBySKU', Mockery::any())
            ->andReturn($response);
    }

    private function mockStockItemById(array $data): void
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->andReturn($data);

        $this->transport->shouldReceive('get')
            ->with('/api/Inventory/GetInventoryItemById', Mockery::any())
            ->andReturn($response);
    }

    /**
     * @return array<string, mixed>
     */
    private static function validStockItemResponse(): array
    {
        return [
            'StockItemId' => self::TEST_STOCK_ITEM_ID,
            'StockItemIntId' => 12345,
            'ItemNumber' => self::TEST_SKU,
            'ItemTitle' => 'Test Product Title',
            'BarcodeNumber' => '1234567890',
            'Quantity' => 100,
            'InOrder' => 5,
            'Due' => 10,
            'Available' => 95,
            'MinimumLevel' => 20,
            'PurchasePrice' => 25.50,
            'RetailPrice' => 49.99,
            'TaxRate' => 20.0,
            'Weight' => 1.5,
            'Height' => 10.0,
            'Width' => 5.0,
            'Depth' => 2.0,
            'CategoryId' => 'category-guid-123',
            'IsCompositeParent' => false,
            'IsBatchedStockType' => false,
            'InventoryTrackingType' => 1,
        ];
    }
}
