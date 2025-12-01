<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Clients;

use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Domain\Exceptions\StockUpdateFailedException;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;
use App\Infrastructure\Shopwired\Clients\StockClient;
use App\Infrastructure\Shopwired\ShopwiredHttpTransport;
use App\Infrastructure\Shopwired\ShopwiredRequestBuilderTrait;
use App\Infrastructure\Shopwired\ShopwiredResponseParserTrait;
use Illuminate\Http\Client\Response;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * StockClient Unit Tests.
 *
 * Tests the ShopWired Stock API client functionality:
 * - Batching logic (max 15 items per request)
 * - Pool request building
 * - Response validation (updated count matches input)
 * - Error handling for invalid responses
 */
#[CoversClass(StockClient::class)]
#[CoversClass(ShopwiredRequestBuilderTrait::class)]
#[CoversClass(ShopwiredResponseParserTrait::class)]
final class StockClientTest extends TestCase
{
    private MockInterface&ShopwiredHttpTransport $transport;

    private StockClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = Mockery::mock(ShopwiredHttpTransport::class);
        $this->client = new StockClient($this->transport);
    }

    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Create a mock Response returning the given JSON data.
     *
     * @param array<mixed>|null $data
     */
    private function mockResponse(?array $data): MockInterface&Response
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->andReturn($data);

        return $response;
    }

    /**
     * Create a list of ItemStockLevel objects.
     *
     * @return list<ItemStockLevel>
     */
    private function createItems(int $count): array
    {
        return \array_map(
            static fn(int $i): ItemStockLevel => new ItemStockLevel("SKU-{$i}", $i * 10),
            \range(1, $count),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Empty Input Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_early_when_items_array_is_empty(): void
    {
        $this->transport->shouldNotReceive('poolPost');

        $this->client->updateStockQuantity([]);

        // No exception = success
        $this->assertTrue(true);
    }

    /*
    |--------------------------------------------------------------------------
    | Single Batch Tests (≤15 items)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_sends_single_batch_for_items_within_batch_size(): void
    {
        $items = $this->createItems(5);

        $this->transport
            ->shouldReceive('poolPost')
            ->once()
            ->with(Mockery::on(function (array $requests): bool {
                $this->assertCount(1, $requests);
                $this->assertArrayHasKey('batch_0', $requests);
                $this->assertSame('stock', $requests['batch_0']['endpoint']);
                $this->assertCount(5, $requests['batch_0']['data']);

                return true;
            }))
            ->andReturn(['batch_0' => $this->mockResponse(['updated' => 5])]);

        $this->client->updateStockQuantity($items);
    }

    #[Test]
    public function it_formats_item_data_correctly_for_api(): void
    {
        $items = [
            new ItemStockLevel('ABC-123', 100),
            new ItemStockLevel('XYZ-789', 50),
        ];

        $this->transport
            ->shouldReceive('poolPost')
            ->once()
            ->with(Mockery::on(function (array $requests): bool {
                $data = $requests['batch_0']['data'];

                $this->assertSame('ABC-123', $data[0]['sku']);
                $this->assertSame(100, $data[0]['quantity']);
                $this->assertSame('XYZ-789', $data[1]['sku']);
                $this->assertSame(50, $data[1]['quantity']);

                return true;
            }))
            ->andReturn(['batch_0' => $this->mockResponse(['updated' => 2])]);

        $this->client->updateStockQuantity($items);
    }

    #[Test]
    public function it_sends_exactly_15_items_in_single_batch(): void
    {
        $items = $this->createItems(15);

        $this->transport
            ->shouldReceive('poolPost')
            ->once()
            ->with(Mockery::on(function (array $requests): bool {
                $this->assertCount(1, $requests);
                $this->assertCount(15, $requests['batch_0']['data']);

                return true;
            }))
            ->andReturn(['batch_0' => $this->mockResponse(['updated' => 15])]);

        $this->client->updateStockQuantity($items);
    }

    /*
    |--------------------------------------------------------------------------
    | Multiple Batch Tests (>15 items)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_splits_into_multiple_batches_when_exceeding_batch_size(): void
    {
        $items = $this->createItems(20);

        $this->transport
            ->shouldReceive('poolPost')
            ->once()
            ->with(Mockery::on(function (array $requests): bool {
                $this->assertCount(2, $requests);
                $this->assertArrayHasKey('batch_0', $requests);
                $this->assertArrayHasKey('batch_1', $requests);
                $this->assertCount(15, $requests['batch_0']['data']);
                $this->assertCount(5, $requests['batch_1']['data']);

                return true;
            }))
            ->andReturn([
                'batch_0' => $this->mockResponse(['updated' => 15]),
                'batch_1' => $this->mockResponse(['updated' => 5]),
            ]);

        $this->client->updateStockQuantity($items);
    }

    #[Test]
    public function it_handles_large_batch_correctly(): void
    {
        $items = $this->createItems(50);

        $this->transport
            ->shouldReceive('poolPost')
            ->once()
            ->with(Mockery::on(function (array $requests): bool {
                // 50 items = 4 batches: 15 + 15 + 15 + 5
                $this->assertCount(4, $requests);
                $this->assertCount(15, $requests['batch_0']['data']);
                $this->assertCount(15, $requests['batch_1']['data']);
                $this->assertCount(15, $requests['batch_2']['data']);
                $this->assertCount(5, $requests['batch_3']['data']);

                return true;
            }))
            ->andReturn([
                'batch_0' => $this->mockResponse(['updated' => 15]),
                'batch_1' => $this->mockResponse(['updated' => 15]),
                'batch_2' => $this->mockResponse(['updated' => 15]),
                'batch_3' => $this->mockResponse(['updated' => 5]),
            ]);

        $this->client->updateStockQuantity($items);
    }

    /*
    |--------------------------------------------------------------------------
    | Response Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_succeeds_when_all_items_are_updated(): void
    {
        $items = $this->createItems(10);

        $this->transport
            ->shouldReceive('poolPost')
            ->andReturn(['batch_0' => $this->mockResponse(['updated' => 10])]);

        // No exception = success
        $this->client->updateStockQuantity($items);

        $this->assertTrue(true);
    }

    #[Test]
    public function it_throws_when_updated_count_is_less_than_expected(): void
    {
        $items = $this->createItems(10);

        $this->transport
            ->shouldReceive('poolPost')
            ->andReturn(['batch_0' => $this->mockResponse(['updated' => 7])]);

        try {
            $this->client->updateStockQuantity($items);
            $this->fail('Expected StockUpdateFailedException');
        } catch (StockUpdateFailedException $e) {
            $this->assertSame(10, $e->expected);
            $this->assertSame(7, $e->actual);
            $this->assertStringContainsString('Expected 10 items updated', $e->reason);
            $this->assertStringContainsString('API reported 7', $e->reason);
            $this->assertCount(10, $e->attemptedItems);
        }
    }

    #[Test]
    public function it_throws_when_updated_count_is_more_than_expected(): void
    {
        $items = $this->createItems(5);

        $this->transport
            ->shouldReceive('poolPost')
            ->andReturn(['batch_0' => $this->mockResponse(['updated' => 8])]);

        try {
            $this->client->updateStockQuantity($items);
            $this->fail('Expected StockUpdateFailedException');
        } catch (StockUpdateFailedException $e) {
            $this->assertSame(5, $e->expected);
            $this->assertSame(8, $e->actual);
        }
    }

    #[Test]
    public function it_includes_all_attempted_items_in_exception(): void
    {
        $items = [
            new ItemStockLevel('SKU-AAA', 10),
            new ItemStockLevel('SKU-BBB', 20),
            new ItemStockLevel('SKU-CCC', 30),
        ];

        $this->transport
            ->shouldReceive('poolPost')
            ->andReturn(['batch_0' => $this->mockResponse(['updated' => 2])]);

        try {
            $this->client->updateStockQuantity($items);
            $this->fail('Expected StockUpdateFailedException');
        } catch (StockUpdateFailedException $e) {
            $this->assertCount(3, $e->attemptedItems);
            $this->assertSame('SKU-AAA', $e->attemptedItems[0]->sku);
            $this->assertSame(10, $e->attemptedItems[0]->quantity);
            $this->assertSame('SKU-BBB', $e->attemptedItems[1]->sku);
            $this->assertSame('SKU-CCC', $e->attemptedItems[2]->sku);
        }
    }

    #[Test]
    public function it_sums_updated_counts_across_multiple_batches(): void
    {
        $items = $this->createItems(25);

        $this->transport
            ->shouldReceive('poolPost')
            ->andReturn([
                'batch_0' => $this->mockResponse(['updated' => 15]),
                'batch_1' => $this->mockResponse(['updated' => 10]),
            ]);

        // 15 + 10 = 25, matches input count
        $this->client->updateStockQuantity($items);

        $this->assertTrue(true);
    }

    #[Test]
    public function it_throws_when_multi_batch_total_does_not_match(): void
    {
        $items = $this->createItems(25);

        $this->transport
            ->shouldReceive('poolPost')
            ->andReturn([
                'batch_0' => $this->mockResponse(['updated' => 15]),
                'batch_1' => $this->mockResponse(['updated' => 8]), // Should be 10
            ]);

        try {
            $this->client->updateStockQuantity($items);
            $this->fail('Expected StockUpdateFailedException');
        } catch (StockUpdateFailedException $e) {
            $this->assertSame(25, $e->expected);
            $this->assertSame(23, $e->actual); // 15 + 8
            $this->assertCount(25, $e->attemptedItems);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Invalid Response Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('invalidUpdatedResponses')]
    public function it_throws_on_invalid_updated_response(mixed $responseData): void
    {
        $items = $this->createItems(5);

        $this->transport
            ->shouldReceive('poolPost')
            ->andReturn(['batch_0' => $this->mockResponse($responseData)]);

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('Expected updated response');

        $this->client->updateStockQuantity($items);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidUpdatedResponses(): array
    {
        return [
            'null response' => [null],
            'empty array' => [[]],
            'missing updated key' => [['count' => 5]],
            'updated is null' => [['updated' => null]],
            'updated is string' => [['updated' => '5']],
            'updated is float' => [['updated' => 5.0]],
            'updated is negative' => [['updated' => -1]],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Transport Exception Propagation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_propagates_external_service_unavailable_exception(): void
    {
        $items = $this->createItems(5);

        $this->transport
            ->shouldReceive('poolPost')
            ->andThrow(new ExternalServiceUnavailableException('Shopwired', 60));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->client->updateStockQuantity($items);
    }

    /*
    |--------------------------------------------------------------------------
    | Edge Case Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_handles_single_item_update(): void
    {
        $items = [new ItemStockLevel('SINGLE-SKU', 42)];

        $this->transport
            ->shouldReceive('poolPost')
            ->once()
            ->with(Mockery::on(function (array $requests): bool {
                $this->assertCount(1, $requests);
                $this->assertCount(1, $requests['batch_0']['data']);
                $this->assertSame('SINGLE-SKU', $requests['batch_0']['data'][0]['sku']);
                $this->assertSame(42, $requests['batch_0']['data'][0]['quantity']);

                return true;
            }))
            ->andReturn(['batch_0' => $this->mockResponse(['updated' => 1])]);

        $this->client->updateStockQuantity($items);
    }

    #[Test]
    public function it_handles_zero_quantity_correctly(): void
    {
        $items = [new ItemStockLevel('ZERO-STOCK', 0)];

        $this->transport
            ->shouldReceive('poolPost')
            ->once()
            ->with(Mockery::on(function (array $requests): bool {
                $this->assertSame(0, $requests['batch_0']['data'][0]['quantity']);

                return true;
            }))
            ->andReturn(['batch_0' => $this->mockResponse(['updated' => 1])]);

        $this->client->updateStockQuantity($items);
    }
}
