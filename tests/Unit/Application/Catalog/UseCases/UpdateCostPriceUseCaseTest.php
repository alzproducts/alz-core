<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\Results\CostPriceUpdateResult;
use App\Application\Contracts\Catalog\ProductSupplierLookupInterface;
use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Application\Linnworks\Resolvers\SupplierGuidResolver;
use App\Application\Linnworks\UpdateCostPriceBySupplier\UpdateCostPriceBySupplierUseCase;
use App\Domain\Catalog\Product\Commands\UpdateCostPriceCommand;
use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\Inventory\ValueObjects\StockItemSupplier;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(UpdateCostPriceBySupplierUseCase::class)]
final class UpdateCostPriceUseCaseTest extends TestCase
{
    private InventoryClientInterface&MockInterface $inventoryClient;

    private InventoryUpdateClientInterface&MockInterface $inventoryUpdateClient;

    private StockItemRepositoryInterface&MockInterface $stockItemRepository;

    private ProductSupplierLookupInterface&MockInterface $supplierLookup;

    private SupplierGuidResolver&MockInterface $supplierGuidResolver;

    private LoggerInterface&MockInterface $logger;

    private UpdateCostPriceBySupplierUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->inventoryClient = Mockery::mock(InventoryClientInterface::class);
        $this->inventoryUpdateClient = Mockery::mock(InventoryUpdateClientInterface::class);
        $this->stockItemRepository = Mockery::mock(StockItemRepositoryInterface::class);
        $this->supplierLookup = Mockery::mock(ProductSupplierLookupInterface::class);
        $this->supplierGuidResolver = Mockery::mock(SupplierGuidResolver::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();

        $this->useCase = new UpdateCostPriceBySupplierUseCase(
            $this->inventoryClient,
            $this->inventoryUpdateClient,
            $this->stockItemRepository,
            $this->supplierLookup,
            $this->supplierGuidResolver,
            $this->logger,
        );
    }

    /**
     * Create a minimal StockItemSupplier stat for a given stockItemId and supplierGuid.
     */
    private function makeSupplierStat(string $stockItemId, Guid $supplierGuid): StockItemSupplier
    {
        return new StockItemSupplier(
            supplierId: $supplierGuid,
            supplierName: 'AcmeCo',
            code: null,
            supplierBarcode: null,
            purchasePrice: Money::exclusive(10.00),
            isDefault: true,
            leadTime: null,
            supplierCurrency: null,
            minPrice: null,
            maxPrice: null,
            averagePrice: null,
            stockItemId: new Guid($stockItemId),
        );
    }

    #[Test]
    public function it_returns_result_on_successful_update(): void
    {
        $sku1 = Sku::fromTrusted('SKU-001');
        $sku2 = Sku::fromTrusted('SKU-002');
        $supplier = new ProductSupplier(supplierName: 'AcmeCo', purchasePrice: 10.0, isDefault: true);
        $supplierGuid = new Guid('00000000-0000-0000-0000-000000000001');
        $stockId1 = '10000000-0000-0000-0000-000000000001';
        $stockId2 = '10000000-0000-0000-0000-000000000002';

        $commands = [
            new UpdateCostPriceCommand(sku: $sku1, costPrice: Money::exclusive(10.50)),
            new UpdateCostPriceCommand(sku: $sku2, costPrice: Money::exclusive(20.00)),
        ];

        $this->supplierLookup
            ->shouldReceive('getByProductSku')
            ->with('SKU-001')
            ->once()
            ->andReturn([$supplier]);

        $this->supplierLookup
            ->shouldReceive('getByProductSku')
            ->with('SKU-002')
            ->once()
            ->andReturn([$supplier]);

        $this->inventoryClient
            ->shouldReceive('resolveStockItemIds')
            ->once()
            ->andReturn([
                'SKU-001' => new Guid($stockId1),
                'SKU-002' => new Guid($stockId2),
            ]);

        $this->supplierGuidResolver
            ->shouldReceive('resolve')
            ->with('AcmeCo')
            ->once()
            ->andReturn($supplierGuid);

        $this->inventoryClient
            ->shouldReceive('getStockSupplierStatsBulk')
            ->once()
            ->andReturn([
                $stockId1 => [$this->makeSupplierStat($stockId1, $supplierGuid)],
                $stockId2 => [$this->makeSupplierStat($stockId2, $supplierGuid)],
            ]);

        $this->inventoryUpdateClient
            ->shouldReceive('updateStockSupplierStats')
            ->with(Mockery::type('array'))
            ->once();

        $this->stockItemRepository
            ->shouldReceive('bulkUpdateSupplierPurchasePrices')
            ->with('AcmeCo', Mockery::type('array'))
            ->once();

        $result = $this->useCase->execute('AcmeCo', $commands);

        self::assertSame(2, $result->total);
        self::assertSame(2, $result->succeeded);
        self::assertSame([], $result->failures);
    }

    #[Test]
    public function it_throws_validation_failed_when_sku_lacks_supplier(): void
    {
        $sku = Sku::fromTrusted('SKU-001');
        $commands = [
            new UpdateCostPriceCommand(sku: $sku, costPrice: Money::exclusive(10.50)),
        ];

        $this->supplierLookup
            ->shouldReceive('getByProductSku')
            ->with('SKU-001')
            ->once()
            ->andReturn([]);

        $this->inventoryClient->shouldNotReceive('resolveStockItemIds');
        $this->inventoryUpdateClient->shouldNotReceive('updateStockSupplierStats');

        $this->expectException(ValidationFailedException::class);

        $this->useCase->execute('AcmeCo', $commands);
    }

    #[Test]
    public function it_skips_local_db_update_for_failed_skus(): void
    {
        $sku1 = Sku::fromTrusted('SKU-001');
        $sku2 = Sku::fromTrusted('SKU-002');
        $supplier = new ProductSupplier(supplierName: 'AcmeCo', purchasePrice: 10.0, isDefault: true);
        $supplierGuid = new Guid('00000000-0000-0000-0000-000000000001');
        $stockId1 = '10000000-0000-0000-0000-000000000001';

        $commands = [
            new UpdateCostPriceCommand(sku: $sku1, costPrice: Money::exclusive(10.50)),
            new UpdateCostPriceCommand(sku: $sku2, costPrice: Money::exclusive(20.00)),
        ];

        $this->supplierLookup
            ->shouldReceive('getByProductSku')
            ->andReturn([$supplier]);

        // Only SKU-001 resolves; SKU-002 is missing
        $this->inventoryClient
            ->shouldReceive('resolveStockItemIds')
            ->once()
            ->andReturn(['SKU-001' => new Guid($stockId1)]);

        $this->supplierGuidResolver
            ->shouldReceive('resolve')
            ->with('AcmeCo')
            ->once()
            ->andReturn($supplierGuid);

        $this->inventoryClient
            ->shouldReceive('getStockSupplierStatsBulk')
            ->once()
            ->andReturn([
                $stockId1 => [$this->makeSupplierStat($stockId1, $supplierGuid)],
            ]);

        $this->inventoryUpdateClient
            ->shouldReceive('updateStockSupplierStats')
            ->once();

        // Bulk update should only include SKU-001's price
        $this->stockItemRepository
            ->shouldReceive('bulkUpdateSupplierPurchasePrices')
            ->with('AcmeCo', Mockery::on(static fn(array $prices): bool => \count($prices) === 1 && isset($prices['SKU-001'])))
            ->once();

        $result = $this->useCase->execute('AcmeCo', $commands);

        self::assertSame(2, $result->total);
        self::assertSame(1, $result->succeeded);
        self::assertCount(1, $result->failures);
        self::assertSame('SKU-002', $result->failures[0]->sku->value);
    }

    #[Test]
    public function it_swallows_local_db_exceptions_and_logs_warning(): void
    {
        $sku = Sku::fromTrusted('SKU-001');
        $supplier = new ProductSupplier(supplierName: 'AcmeCo', purchasePrice: 10.0, isDefault: true);
        $supplierGuid = new Guid('00000000-0000-0000-0000-000000000001');
        $stockId1 = '10000000-0000-0000-0000-000000000001';

        $commands = [
            new UpdateCostPriceCommand(sku: $sku, costPrice: Money::exclusive(10.50)),
        ];

        $this->supplierLookup
            ->shouldReceive('getByProductSku')
            ->once()
            ->andReturn([$supplier]);

        $this->inventoryClient
            ->shouldReceive('resolveStockItemIds')
            ->once()
            ->andReturn(['SKU-001' => new Guid($stockId1)]);

        $this->supplierGuidResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($supplierGuid);

        $this->inventoryClient
            ->shouldReceive('getStockSupplierStatsBulk')
            ->once()
            ->andReturn([
                $stockId1 => [$this->makeSupplierStat($stockId1, $supplierGuid)],
            ]);

        $this->inventoryUpdateClient
            ->shouldReceive('updateStockSupplierStats')
            ->once();

        $this->stockItemRepository
            ->shouldReceive('bulkUpdateSupplierPurchasePrices')
            ->once()
            ->andThrow(new DatabaseOperationFailedException(
                operation: 'bulkUpdateSupplierPurchasePrices',
                reason: 'Connection failed',
            ));

        $this->logger
            ->shouldReceive('warning')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => \array_key_exists('count', $context));

        // No exception should propagate
        $result = $this->useCase->execute('AcmeCo', $commands);

        self::assertInstanceOf(CostPriceUpdateResult::class, $result);
    }

    #[Test]
    public function it_marks_all_resolved_as_failed_when_bulk_api_call_fails(): void
    {
        $sku1 = Sku::fromTrusted('SKU-001');
        $sku2 = Sku::fromTrusted('SKU-002');
        $supplier = new ProductSupplier(supplierName: 'AcmeCo', purchasePrice: 10.0, isDefault: true);
        $supplierGuid = new Guid('00000000-0000-0000-0000-000000000001');
        $stockId1 = '10000000-0000-0000-0000-000000000001';
        $stockId2 = '10000000-0000-0000-0000-000000000002';

        $commands = [
            new UpdateCostPriceCommand(sku: $sku1, costPrice: Money::exclusive(10.50)),
            new UpdateCostPriceCommand(sku: $sku2, costPrice: Money::exclusive(20.00)),
        ];

        $this->supplierLookup
            ->shouldReceive('getByProductSku')
            ->andReturn([$supplier]);

        $this->inventoryClient
            ->shouldReceive('resolveStockItemIds')
            ->once()
            ->andReturn([
                'SKU-001' => new Guid($stockId1),
                'SKU-002' => new Guid($stockId2),
            ]);

        $this->supplierGuidResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($supplierGuid);

        $this->inventoryClient
            ->shouldReceive('getStockSupplierStatsBulk')
            ->once()
            ->andReturn([
                $stockId1 => [$this->makeSupplierStat($stockId1, $supplierGuid)],
                $stockId2 => [$this->makeSupplierStat($stockId2, $supplierGuid)],
            ]);

        $this->inventoryUpdateClient
            ->shouldReceive('updateStockSupplierStats')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Linnworks'));

        $this->logger
            ->shouldReceive('warning')
            ->once()
            ->withArgs(static fn(string $msg): bool => \str_contains($msg, 'Bulk supplier price update API call failed'));

        // Local DB should NOT be called — all items failed
        $this->stockItemRepository->shouldNotReceive('bulkUpdateSupplierPurchasePrices');

        $result = $this->useCase->execute('AcmeCo', $commands);

        self::assertSame(2, $result->total);
        self::assertSame(0, $result->succeeded);
        self::assertCount(2, $result->failures);
        self::assertStringContainsString('Linnworks API error:', $result->failures[0]->error);
    }

    #[Test]
    public function it_marks_sku_as_failed_when_supplier_stat_not_found_in_linnworks(): void
    {
        $sku1 = Sku::fromTrusted('SKU-001');
        $sku2 = Sku::fromTrusted('SKU-002');
        $supplier = new ProductSupplier(supplierName: 'AcmeCo', purchasePrice: 10.0, isDefault: true);
        $supplierGuid = new Guid('00000000-0000-0000-0000-000000000001');
        $stockId1 = '10000000-0000-0000-0000-000000000001';
        $stockId2 = '10000000-0000-0000-0000-000000000002';

        $commands = [
            new UpdateCostPriceCommand(sku: $sku1, costPrice: Money::exclusive(10.50)),
            new UpdateCostPriceCommand(sku: $sku2, costPrice: Money::exclusive(20.00)),
        ];

        $this->supplierLookup
            ->shouldReceive('getByProductSku')
            ->andReturn([$supplier]);

        $this->inventoryClient
            ->shouldReceive('resolveStockItemIds')
            ->once()
            ->andReturn([
                'SKU-001' => new Guid($stockId1),
                'SKU-002' => new Guid($stockId2),
            ]);

        $this->supplierGuidResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($supplierGuid);

        // Only SKU-001's stat is present; SKU-002's stat is missing
        $this->inventoryClient
            ->shouldReceive('getStockSupplierStatsBulk')
            ->once()
            ->andReturn([
                $stockId1 => [$this->makeSupplierStat($stockId1, $supplierGuid)],
            ]);

        // Only SKU-001 gets sent to the API (SKU-002 was filtered out at merge)
        $this->inventoryUpdateClient
            ->shouldReceive('updateStockSupplierStats')
            ->once();

        $this->stockItemRepository
            ->shouldReceive('bulkUpdateSupplierPurchasePrices')
            ->with('AcmeCo', Mockery::on(static fn(array $prices): bool => \count($prices) === 1 && isset($prices['SKU-001'])))
            ->once();

        $result = $this->useCase->execute('AcmeCo', $commands);

        self::assertSame(2, $result->total);
        self::assertSame(1, $result->succeeded);
        self::assertCount(1, $result->failures);
        self::assertSame('SKU-002', $result->failures[0]->sku->value);
        self::assertStringContainsString('Supplier stat not found', $result->failures[0]->error);
    }

    #[Test]
    public function it_deduplicates_supplier_lookup_per_unique_sku(): void
    {
        $sku = Sku::fromTrusted('SKU-001');
        $supplier = new ProductSupplier(supplierName: 'AcmeCo', purchasePrice: 10.0, isDefault: true);
        $supplierGuid = new Guid('00000000-0000-0000-0000-000000000001');
        $stockId1 = '10000000-0000-0000-0000-000000000001';

        // Two commands with the same SKU
        $commands = [
            new UpdateCostPriceCommand(sku: $sku, costPrice: Money::exclusive(10.50)),
            new UpdateCostPriceCommand(sku: $sku, costPrice: Money::exclusive(15.00)),
        ];

        // Must only be called once despite two commands with the same SKU
        $this->supplierLookup
            ->shouldReceive('getByProductSku')
            ->with('SKU-001')
            ->once()
            ->andReturn([$supplier]);

        $this->inventoryClient
            ->shouldReceive('resolveStockItemIds')
            ->once()
            ->andReturn(['SKU-001' => new Guid($stockId1)]);

        $this->supplierGuidResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($supplierGuid);

        $this->inventoryClient
            ->shouldReceive('getStockSupplierStatsBulk')
            ->once()
            ->andReturn([
                $stockId1 => [$this->makeSupplierStat($stockId1, $supplierGuid)],
            ]);

        $this->inventoryUpdateClient
            ->shouldReceive('updateStockSupplierStats')
            ->once();

        $this->stockItemRepository
            ->shouldReceive('bulkUpdateSupplierPurchasePrices')
            ->once();

        $this->useCase->execute('AcmeCo', $commands);
    }
}
