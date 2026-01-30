<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Inventory\Services;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Application\Contracts\LockManagerInterface;
use App\Application\Inventory\Params\CreateStockItemParams;
use App\Application\Inventory\Services\LinnworksStockItemCreatorService;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\Money;
use App\Domain\ValueObjects\TaxRate;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Tests for LinnworksStockItemCreatorService.
 *
 * Per TestingStrategy.md: Tests atomic transaction logic including
 * lock acquisition, item creation, and rollback on failure.
 */
#[CoversClass(LinnworksStockItemCreatorService::class)]
final class LinnworksStockItemCreatorServiceTest extends TestCase
{
    private InventoryClientInterface&MockInterface $inventoryClient;

    private InventoryUpdateClientInterface&MockInterface $inventoryUpdateClient;

    private LockManagerInterface&MockInterface $lockManager;

    private LoggerInterface&MockInterface $logger;

    private LinnworksStockItemCreatorService $service;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->inventoryClient = Mockery::mock(InventoryClientInterface::class);
        $this->inventoryUpdateClient = Mockery::mock(InventoryUpdateClientInterface::class);
        $this->lockManager = Mockery::mock(LockManagerInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->service = new LinnworksStockItemCreatorService(
            $this->inventoryClient,
            $this->inventoryUpdateClient,
            $this->lockManager,
            $this->logger,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Happy Path Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_complete_stock_item_successfully(): void
    {
        $params = $this->createParams(imageUrl: 'https://example.com/image.jpg');
        $generatedSku = Sku::fromTrusted('NEW-12345');
        $stockItemId = Guid::fromTrusted('550e8400-e29b-41d4-a716-446655440010');

        // Lock executes callback
        $this->lockManager->shouldReceive('withLock')
            ->once()
            ->andReturnUsing(function (string $name, int $timeout, callable $callback) use ($generatedSku, $stockItemId): array {
                // Simulate what happens inside the lock
                $this->inventoryClient->shouldReceive('getNewItemNumber')
                    ->once()
                    ->andReturn($generatedSku);

                $this->inventoryUpdateClient->shouldReceive('addInventoryItem')
                    ->once()
                    ->andReturn($stockItemId);

                return $callback();
            });

        // Supplier linking - verify isDefault=true
        $this->inventoryUpdateClient->shouldReceive('createSupplierStat')
            ->once()
            ->withArgs(static fn($id, $supplierId, $purchasePrice, $supplierCode, $isDefault) => $id->value === $stockItemId->value && $isDefault === true);

        // Extended properties
        $this->inventoryUpdateClient->shouldReceive('addExtendedProperty')
            ->once()
            ->withArgs(static fn($id, $name, $value) => $name === 'ShopID' && $value === '12345');

        // Image
        $this->inventoryUpdateClient->shouldReceive('addImage')
            ->once()
            ->withArgs(static fn($id, $url) => $url === 'https://example.com/image.jpg');

        [$sku, $itemId] = $this->service->create($params);

        $this->assertSame('NEW-12345', $sku->value);
        $this->assertSame($stockItemId->value, $itemId->value);
    }

    #[Test]
    public function it_returns_sku_and_stock_item_id_tuple(): void
    {
        $params = $this->createParams();
        $generatedSku = Sku::fromTrusted('SKU-99999');
        $stockItemId = Guid::fromTrusted('550e8400-e29b-41d4-a716-446655440011');

        $this->setupSuccessfulLock($generatedSku, $stockItemId);
        $this->setupSuccessfulCompletion();

        $result = $this->service->create($params);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertInstanceOf(Sku::class, $result[0]);
        $this->assertInstanceOf(Guid::class, $result[1]);
        $this->assertSame('SKU-99999', $result[0]->value);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440011', $result[1]->value);
    }

    #[Test]
    public function it_skips_image_when_url_is_null(): void
    {
        $params = $this->createParams(imageUrl: null);
        $generatedSku = Sku::fromTrusted('NO-IMAGE-SKU');
        $stockItemId = Guid::fromTrusted('550e8400-e29b-41d4-a716-446655440012');

        $this->setupSuccessfulLock($generatedSku, $stockItemId);

        // Supplier and EP called, but NOT image
        $this->inventoryUpdateClient->shouldReceive('createSupplierStat')->once();
        $this->inventoryUpdateClient->shouldReceive('addExtendedProperty')->once();
        $this->inventoryUpdateClient->shouldNotReceive('addImage');

        $this->service->create($params);

        $this->assertTrue(true); // Reached without image call
    }

    /*
    |--------------------------------------------------------------------------
    | Lock Failure Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_when_lock_acquisition_fails(): void
    {
        $params = $this->createParams();

        $this->lockManager->shouldReceive('withLock')
            ->once()
            ->andThrow(new LockAcquisitionException('sku-generation', 30));

        // No API calls should be made
        $this->inventoryClient->shouldNotReceive('getNewItemNumber');
        $this->inventoryUpdateClient->shouldNotReceive('addInventoryItem');

        $this->expectException(LockAcquisitionException::class);
        $this->expectExceptionMessage("Failed to acquire lock 'sku-generation'");

        $this->service->create($params);
    }

    /*
    |--------------------------------------------------------------------------
    | Pre-Item-Creation Failure Tests (No Rollback Needed)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_propagates_sku_generation_failure(): void
    {
        $params = $this->createParams();

        $this->lockManager->shouldReceive('withLock')
            ->once()
            ->andReturnUsing(function (string $name, int $timeout, callable $callback): void {
                $this->inventoryClient->shouldReceive('getNewItemNumber')
                    ->once()
                    ->andThrow(new ExternalServiceUnavailableException('Linnworks'));

                $callback();
            });

        // No item created = no rollback needed
        $this->inventoryUpdateClient->shouldNotReceive('deleteInventoryItem');

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->service->create($params);
    }

    #[Test]
    public function it_propagates_item_creation_failure(): void
    {
        $params = $this->createParams();

        $this->lockManager->shouldReceive('withLock')
            ->once()
            ->andReturnUsing(function (string $name, int $timeout, callable $callback): void {
                $this->inventoryClient->shouldReceive('getNewItemNumber')
                    ->once()
                    ->andReturn(Sku::fromTrusted('WILL-FAIL'));

                $this->inventoryUpdateClient->shouldReceive('addInventoryItem')
                    ->once()
                    ->andThrow(new InvalidApiRequestException('Linnworks', 'Invalid category'));

                $callback();
            });

        // No item created (addInventoryItem failed) = no rollback needed
        $this->inventoryUpdateClient->shouldNotReceive('deleteInventoryItem');

        $this->expectException(InvalidApiRequestException::class);

        $this->service->create($params);
    }

    /*
    |--------------------------------------------------------------------------
    | Post-Item-Creation Failure Tests (Rollback Triggered)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_rolls_back_when_supplier_linking_fails(): void
    {
        $params = $this->createParams();
        $generatedSku = Sku::fromTrusted('ROLLBACK-SKU');
        $stockItemId = Guid::fromTrusted('550e8400-e29b-41d4-a716-446655440013');

        $this->setupSuccessfulLock($generatedSku, $stockItemId);

        // Supplier linking fails
        $this->inventoryUpdateClient->shouldReceive('createSupplierStat')
            ->once()
            ->andThrow(new ResourceNotFoundException('Linnworks', 'Supplier', 'supplier-id'));

        // Rollback should be triggered
        $this->inventoryUpdateClient->shouldReceive('deleteInventoryItem')
            ->once()
            ->with(Mockery::on(static fn($id) => $id->value === $stockItemId->value));

        $this->expectException(ResourceNotFoundException::class);

        $this->service->create($params);
    }

    #[Test]
    public function it_rolls_back_when_extended_property_fails(): void
    {
        $params = $this->createParams();
        $generatedSku = Sku::fromTrusted('EP-FAIL-SKU');
        $stockItemId = Guid::fromTrusted('550e8400-e29b-41d4-a716-446655440014');

        $this->setupSuccessfulLock($generatedSku, $stockItemId);

        // Supplier succeeds
        $this->inventoryUpdateClient->shouldReceive('createSupplierStat')->once();

        // EP fails
        $this->inventoryUpdateClient->shouldReceive('addExtendedProperty')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Linnworks'));

        // Rollback triggered
        $this->inventoryUpdateClient->shouldReceive('deleteInventoryItem')
            ->once()
            ->with(Mockery::on(static fn($id) => $id->value === $stockItemId->value));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->service->create($params);
    }

    #[Test]
    public function it_logs_critical_when_rollback_fails(): void
    {
        $params = $this->createParams();
        $generatedSku = Sku::fromTrusted('ORPHAN-SKU');
        $stockItemId = Guid::fromTrusted('550e8400-e29b-41d4-a716-446655440015');

        $this->setupSuccessfulLock($generatedSku, $stockItemId);

        // Supplier fails
        $this->inventoryUpdateClient->shouldReceive('createSupplierStat')
            ->once()
            ->andThrow(new ResourceNotFoundException('Linnworks', 'Supplier', 'bad-supplier'));

        // Rollback also fails
        $this->inventoryUpdateClient->shouldReceive('deleteInventoryItem')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Linnworks'));

        // Critical log for orphaned item
        $this->logger->shouldReceive('critical')
            ->once()
            ->with(
                'Failed to rollback Linnworks item - manual cleanup required',
                Mockery::on(static fn($ctx) => $ctx['stock_item_id'] === '550e8400-e29b-41d4-a716-446655440015'),
            );

        // Original exception still thrown (not rollback exception)
        $this->expectException(ResourceNotFoundException::class);

        $this->service->create($params);
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures & Helpers
    |--------------------------------------------------------------------------
    */

    private function createParams(?string $imageUrl = null): CreateStockItemParams
    {
        return new CreateStockItemParams(
            categoryId: Guid::fromTrusted('550e8400-e29b-41d4-a716-446655440001'),
            title: 'Test Product - Large Blue',
            retailPrice: Money::inclusive(29.99),
            taxRate: TaxRate::standard(),
            supplierId: Guid::fromTrusted('550e8400-e29b-41d4-a716-446655440002'),
            purchasePrice: Money::exclusive(15.00),
            barcode: null,
            mpn: 'MPN-123',
            supplierCode: 'SUP-CODE',
            extendedProperties: ['ShopID' => '12345'],
            imageUrl: $imageUrl,
        );
    }

    private function setupSuccessfulLock(Sku $sku, Guid $stockItemId): void
    {
        $this->lockManager->shouldReceive('withLock')
            ->once()
            ->andReturnUsing(function (string $name, int $timeout, callable $callback) use ($sku, $stockItemId): array {
                $this->inventoryClient->shouldReceive('getNewItemNumber')
                    ->once()
                    ->andReturn($sku);

                $this->inventoryUpdateClient->shouldReceive('addInventoryItem')
                    ->once()
                    ->andReturn($stockItemId);

                return $callback();
            });
    }

    private function setupSuccessfulCompletion(): void
    {
        $this->inventoryUpdateClient->shouldReceive('createSupplierStat')->once();
        $this->inventoryUpdateClient->shouldReceive('addExtendedProperty')->once();
    }
}
