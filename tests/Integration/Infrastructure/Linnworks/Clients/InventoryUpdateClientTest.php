<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Linnworks\Clients;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Inventory\Enums\LinnworksInventoryField;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Clients\InventoryUpdateClient;
use App\Infrastructure\Linnworks\LinnworksConfig;
use App\Infrastructure\Linnworks\LinnworksHttpTransport;
use App\Infrastructure\Linnworks\LinnworksSession;
use App\Infrastructure\Linnworks\LinnworksSessionManager;
use DateTimeImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Integration tests for InventoryUpdateClient.
 *
 * Tests the HTTP boundary behavior for Linnworks inventory updates.
 * Per TestingStrategy.md: 1-2 integration tests at HTTP boundary.
 */
#[CoversClass(InventoryUpdateClient::class)]
#[Group('integration')]
final class InventoryUpdateClientTest extends TestCase
{
    private const string TEST_SERVER_URL = 'https://eu-ext.linnworks.net';
    private const string TEST_TOKEN = 'test-auth-token';
    private const string TEST_STOCK_ITEM_ID = '550e8400-e29b-41d4-a716-446655440000';

    private LinnworksSessionManager&MockInterface $sessionManager;

    private InventoryClientInterface&MockInterface $inventoryClient;

    private InventoryUpdateClient $client;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionManager = Mockery::mock(LinnworksSessionManager::class);
        $this->sessionManager->allows('getSession')->andReturn($this->createValidSession());
        $this->sessionManager->allows('invalidate');

        $this->inventoryClient = Mockery::mock(InventoryClientInterface::class);

        $config = new LinnworksConfig(
            applicationId: 'test-app-id',
            applicationSecret: 'test-app-secret',
            installationToken: 'test-install-token',
        );

        $transport = new LinnworksHttpTransport($config, $this->sessionManager);

        $this->client = new InventoryUpdateClient($transport, $this->inventoryClient);
    }

    private function createValidSession(): LinnworksSession
    {
        return new LinnworksSession(
            token: self::TEST_TOKEN,
            serverUrl: self::TEST_SERVER_URL,
            expiresAt: new DateTimeImmutable('+1 hour'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Happy Path Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_updates_sku_using_guid_identifier(): void
    {
        Http::fake([
            self::TEST_SERVER_URL . '/api/Inventory/UpdateInventoryItemField' => Http::response(null, 204),
        ]);

        $guid = new Guid(self::TEST_STOCK_ITEM_ID);
        $newSku = Sku::fromTrusted('NEW-SKU-123');

        $this->inventoryClient
            ->shouldReceive('resolveStockItemId')
            ->once()
            ->with($guid)
            ->andReturn($guid);

        $this->client->updateSku($guid, $newSku);

        Http::assertSent(function (Request $request) {
            // Verify endpoint
            $this->assertStringEndsWith('/api/Inventory/UpdateInventoryItemField', $request->url());

            // Verify it's a POST request with form params
            $this->assertSame('POST', $request->method());

            // Verify form parameters
            $this->assertSame(self::TEST_STOCK_ITEM_ID, $request->data()['inventoryItemId']);
            $this->assertSame(LinnworksInventoryField::SKU->value, $request->data()['fieldName']);
            $this->assertSame('NEW-SKU-123', $request->data()['fieldValue']);

            // Verify auth header
            $this->assertSame([self::TEST_TOKEN], $request->header('Authorization'));

            return true;
        });
    }

    #[Test]
    public function it_updates_sku_by_resolving_sku_to_guid(): void
    {
        Http::fake([
            self::TEST_SERVER_URL . '/api/Inventory/UpdateInventoryItemField' => Http::response(null, 204),
        ]);

        $currentSku = Sku::fromTrusted('OLD-SKU');
        $newSku = Sku::fromTrusted('RESOLVED-NEW-SKU');
        $resolvedGuid = new Guid(self::TEST_STOCK_ITEM_ID);

        $this->inventoryClient
            ->shouldReceive('resolveStockItemId')
            ->once()
            ->with($currentSku)
            ->andReturn($resolvedGuid);

        $this->client->updateSku($currentSku, $newSku);

        Http::assertSent(function (Request $request) {
            // Should use resolved stockItemId from resolveStockItemId()
            $this->assertSame(self::TEST_STOCK_ITEM_ID, $request->data()['inventoryItemId']);
            $this->assertSame('RESOLVED-NEW-SKU', $request->data()['fieldValue']);

            return true;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Error Path Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_invalid_api_request_on_400_response(): void
    {
        Http::fake([
            self::TEST_SERVER_URL . '/api/Inventory/UpdateInventoryItemField' => Http::response(
                ['Message' => 'SKU already exists'],
                400,
            ),
        ]);

        $guid = new Guid(self::TEST_STOCK_ITEM_ID);
        $newSku = Sku::fromTrusted('DUPLICATE-SKU');

        $this->inventoryClient
            ->shouldReceive('resolveStockItemId')
            ->once()
            ->with($guid)
            ->andReturn($guid);

        try {
            $this->client->updateSku($guid, $newSku);
            $this->fail('Expected InvalidApiRequestException');
        } catch (InvalidApiRequestException $e) {
            $this->assertSame('API request validation failed', $e->getMessage());
            $this->assertSame('SKU already exists', $e->detail);
        }
    }
}
