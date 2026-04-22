<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Linnworks\Clients;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Inventory\Enums\LinnworksInventoryField;
use App\Domain\Inventory\ValueObjects\InventoryFieldUpdate;
use App\Domain\Inventory\ValueObjects\Weight;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Clients\InventoryFieldUpdateClient;
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
 * Integration tests for InventoryFieldUpdateClient.
 *
 * Tests the HTTP boundary behavior for Linnworks inventory field updates.
 * Per TestingStrategy.md: 1-2 integration tests at HTTP boundary.
 */
#[CoversClass(InventoryFieldUpdateClient::class)]
#[Group('integration')]
final class InventoryFieldUpdateClientTest extends TestCase
{
    private const string TEST_SERVER_URL = 'https://eu-ext.linnworks.net';
    private const string TEST_TOKEN = 'test-auth-token';
    private const string TEST_STOCK_ITEM_ID = '550e8400-e29b-41d4-a716-446655440000';

    private LinnworksSessionManager&MockInterface $sessionManager;

    private InventoryClientInterface&MockInterface $inventoryClient;

    private InventoryFieldUpdateClient $client;

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

        $this->client = new InventoryFieldUpdateClient($transport, $this->inventoryClient);
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
    public function it_updates_a_field_using_guid_identifier(): void
    {
        Http::fake([
            self::TEST_SERVER_URL . '/api/Inventory/UpdateInventoryItemField' => Http::response(null, 204),
        ]);

        $guid = new Guid(self::TEST_STOCK_ITEM_ID);

        $this->inventoryClient
            ->shouldReceive('resolveStockItemId')
            ->once()
            ->with(Mockery::type(Guid::class))
            ->andReturn($guid);

        $this->client->updateFields($guid, InventoryFieldUpdate::category('Accessories'));

        Http::assertSent(function (Request $request) {
            $this->assertStringEndsWith('/api/Inventory/UpdateInventoryItemField', $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertSame(self::TEST_STOCK_ITEM_ID, $request->data()['inventoryItemId']);
            $this->assertSame(LinnworksInventoryField::Category->value, $request->data()['fieldName']);
            $this->assertSame('Accessories', $request->data()['fieldValue']);
            $this->assertSame([self::TEST_TOKEN], $request->header('Authorization'));

            return true;
        });
    }

    #[Test]
    public function it_resolves_sku_identifier_and_sends_correct_field_params(): void
    {
        Http::fake([
            self::TEST_SERVER_URL . '/api/Inventory/UpdateInventoryItemField' => Http::response(null, 204),
        ]);

        $resolvedGuid = new Guid(self::TEST_STOCK_ITEM_ID);
        $sku = Sku::fromTrusted('TEST-SKU-001');

        $this->inventoryClient
            ->shouldReceive('resolveStockItemId')
            ->once()
            ->with(Mockery::type(Sku::class))
            ->andReturn($resolvedGuid);

        $this->client->updateFields($sku, InventoryFieldUpdate::minimumLevel(5));

        Http::assertSent(function (Request $request) {
            $this->assertSame(self::TEST_STOCK_ITEM_ID, $request->data()['inventoryItemId']);
            $this->assertSame(LinnworksInventoryField::MinimumLevel->value, $request->data()['fieldName']);
            $this->assertSame('5', $request->data()['fieldValue']);

            return true;
        });
    }

    #[Test]
    public function it_issues_one_api_call_per_field_update(): void
    {
        Http::fake([
            self::TEST_SERVER_URL . '/api/Inventory/UpdateInventoryItemField' => Http::response(null, 204),
        ]);

        $guid = new Guid(self::TEST_STOCK_ITEM_ID);

        $this->inventoryClient
            ->shouldReceive('resolveStockItemId')
            ->once()
            ->andReturn($guid);

        $this->client->updateFields(
            $guid,
            InventoryFieldUpdate::category('Electronics'),
            InventoryFieldUpdate::minimumLevel(10),
            InventoryFieldUpdate::jit(true),
        );

        Http::assertSentCount(3);
    }

    #[Test]
    public function it_serialises_all_field_types_correctly(): void
    {
        Http::fake([
            self::TEST_SERVER_URL . '/api/Inventory/UpdateInventoryItemField' => Http::response(null, 204),
        ]);

        $guid = new Guid(self::TEST_STOCK_ITEM_ID);

        $this->inventoryClient
            ->shouldReceive('resolveStockItemId')
            ->once()
            ->andReturn($guid);

        $retailPrice = Money::inclusive(10.0);
        $purchasePrice = Money::exclusive(6.0);
        $barcode = Gtin::fromTrusted('73513537');
        $weight = Weight::kilogram(1.5);

        $this->client->updateFields(
            $guid,
            InventoryFieldUpdate::category('Clothing'),
            InventoryFieldUpdate::minimumLevel(3),
            InventoryFieldUpdate::jit(false),
            InventoryFieldUpdate::retailPrice($retailPrice),
            InventoryFieldUpdate::purchasePrice($purchasePrice),
            InventoryFieldUpdate::binRack('A-01-02'),
            InventoryFieldUpdate::barcode($barcode),
            InventoryFieldUpdate::weight($weight),
            InventoryFieldUpdate::title('Test Product Title'),
        );

        Http::assertSentCount(9);

        /** @var list<array{fieldName: string, fieldValue: string}> $sentFields */
        $sentFields = [];
        Http::assertSent(static function (Request $request) use (&$sentFields) {
            $sentFields[] = [
                'fieldName' => $request->data()['fieldName'],
                'fieldValue' => $request->data()['fieldValue'],
            ];

            return true;
        });

        $this->assertContains(['fieldName' => 'RetailPrice', 'fieldValue' => '10'], $sentFields);
        $this->assertContains(['fieldName' => 'JIT', 'fieldValue' => 'false'], $sentFields);
        $this->assertContains(['fieldName' => 'Weight', 'fieldValue' => '1.5'], $sentFields);
        $this->assertContains(['fieldName' => 'Barcode', 'fieldValue' => '73513537'], $sentFields);
    }

    #[Test]
    public function it_does_nothing_when_no_updates_provided(): void
    {
        Http::fake();

        $guid = new Guid(self::TEST_STOCK_ITEM_ID);

        $this->client->updateFields($guid);

        Http::assertNothingSent();
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
                ['Message' => 'Invalid field value'],
                400,
            ),
        ]);

        $guid = new Guid(self::TEST_STOCK_ITEM_ID);

        $this->inventoryClient
            ->shouldReceive('resolveStockItemId')
            ->once()
            ->andReturn($guid);

        try {
            $this->client->updateFields($guid, InventoryFieldUpdate::category('Bad'));
            $this->fail('Expected InvalidApiRequestException');
        } catch (InvalidApiRequestException $e) {
            $this->assertSame('API request validation failed', $e->getMessage());
            $this->assertSame('Invalid field value', $e->detail);
        }
    }
}
