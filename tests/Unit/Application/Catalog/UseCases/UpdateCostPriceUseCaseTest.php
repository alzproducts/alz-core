<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\Results\CostPriceUpdateResult;
use App\Application\Contracts\Catalog\ProductSupplierLookupInterface;
use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Application\Linnworks\UpdateCostPrice\SupplierGuidResolver;
use App\Application\Linnworks\UpdateCostPrice\UpdateCostPriceUseCase;
use App\Domain\Catalog\Product\Commands\UpdateCostPriceCommand;
use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(UpdateCostPriceUseCase::class)]
final class UpdateCostPriceUseCaseTest extends TestCase
{
    private InventoryClientInterface&MockInterface $inventoryClient;

    private InventoryUpdateClientInterface&MockInterface $inventoryUpdateClient;

    private StockItemRepositoryInterface&MockInterface $stockItemRepository;

    private ProductSupplierLookupInterface&MockInterface $supplierLookup;

    private SupplierGuidResolver&MockInterface $supplierGuidResolver;

    private LoggerInterface&MockInterface $logger;

    private UpdateCostPriceUseCase $useCase;

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

        $this->useCase = new UpdateCostPriceUseCase(
            $this->inventoryClient,
            $this->inventoryUpdateClient,
            $this->stockItemRepository,
            $this->supplierLookup,
            $this->supplierGuidResolver,
            $this->logger,
        );
    }

    #[Test]
    public function it_returns_result_on_successful_update(): void
    {
        $sku1 = Sku::fromTrusted('SKU-001');
        $sku2 = Sku::fromTrusted('SKU-002');
        $supplier = new ProductSupplier(supplierName: 'AcmeCo', purchasePrice: 10.0, isDefault: true);
        $supplierGuid = new Guid('00000000-0000-0000-0000-000000000001');

        $commands = [
            new UpdateCostPriceCommand(sku: $sku1, costPrice: Money::exclusive(10.50), supplierName: 'AcmeCo'),
            new UpdateCostPriceCommand(sku: $sku2, costPrice: Money::exclusive(20.00), supplierName: 'AcmeCo'),
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
                'SKU-001' => new Guid('10000000-0000-0000-0000-000000000001'),
                'SKU-002' => new Guid('10000000-0000-0000-0000-000000000002'),
            ]);

        $this->supplierGuidResolver
            ->shouldReceive('resolve')
            ->with('AcmeCo')
            ->once()
            ->andReturn($supplierGuid);

        $this->inventoryUpdateClient
            ->shouldReceive('updateBulkSupplierStats')
            ->with($supplierGuid, Mockery::type('array'))
            ->once();

        $this->stockItemRepository
            ->shouldReceive('updateSupplierPurchasePrice')
            ->twice();

        $result = $this->useCase->execute($commands);

        self::assertSame(2, $result->total);
        self::assertSame(2, $result->succeeded);
        self::assertSame([], $result->failures);
    }

    #[Test]
    public function it_throws_validation_failed_when_sku_lacks_supplier(): void
    {
        $sku = Sku::fromTrusted('SKU-001');
        $commands = [
            new UpdateCostPriceCommand(sku: $sku, costPrice: Money::exclusive(10.50), supplierName: 'AcmeCo'),
        ];

        $this->supplierLookup
            ->shouldReceive('getByProductSku')
            ->with('SKU-001')
            ->once()
            ->andReturn([]);

        $this->inventoryClient->shouldNotReceive('resolveStockItemIds');
        $this->inventoryUpdateClient->shouldNotReceive('updateBulkSupplierStats');

        $this->expectException(ValidationFailedException::class);

        $this->useCase->execute($commands);
    }

    #[Test]
    public function it_skips_local_db_update_for_failed_skus(): void
    {
        $sku1 = Sku::fromTrusted('SKU-001');
        $sku2 = Sku::fromTrusted('SKU-002');
        $supplier = new ProductSupplier(supplierName: 'AcmeCo', purchasePrice: 10.0, isDefault: true);
        $supplierGuid = new Guid('00000000-0000-0000-0000-000000000001');

        $commands = [
            new UpdateCostPriceCommand(sku: $sku1, costPrice: Money::exclusive(10.50), supplierName: 'AcmeCo'),
            new UpdateCostPriceCommand(sku: $sku2, costPrice: Money::exclusive(20.00), supplierName: 'AcmeCo'),
        ];

        $this->supplierLookup
            ->shouldReceive('getByProductSku')
            ->andReturn([$supplier]);

        // Only SKU-001 resolves; SKU-002 is missing
        $this->inventoryClient
            ->shouldReceive('resolveStockItemIds')
            ->once()
            ->andReturn(['SKU-001' => new Guid('10000000-0000-0000-0000-000000000001')]);

        $this->supplierGuidResolver
            ->shouldReceive('resolve')
            ->with('AcmeCo')
            ->once()
            ->andReturn($supplierGuid);

        $this->inventoryUpdateClient
            ->shouldReceive('updateBulkSupplierStats')
            ->once();

        // Only SKU-001 should be updated locally; SKU-002 is in failures
        $this->stockItemRepository
            ->shouldReceive('updateSupplierPurchasePrice')
            ->with($sku1, 'AcmeCo', Mockery::any())
            ->once();

        $this->stockItemRepository
            ->shouldNotReceive('updateSupplierPurchasePrice')
            ->with($sku2, Mockery::any(), Mockery::any());

        $result = $this->useCase->execute($commands);

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

        $commands = [
            new UpdateCostPriceCommand(sku: $sku, costPrice: Money::exclusive(10.50), supplierName: 'AcmeCo'),
        ];

        $this->supplierLookup
            ->shouldReceive('getByProductSku')
            ->once()
            ->andReturn([$supplier]);

        $this->inventoryClient
            ->shouldReceive('resolveStockItemIds')
            ->once()
            ->andReturn(['SKU-001' => new Guid('10000000-0000-0000-0000-000000000001')]);

        $this->supplierGuidResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($supplierGuid);

        $this->inventoryUpdateClient
            ->shouldReceive('updateBulkSupplierStats')
            ->once();

        $this->stockItemRepository
            ->shouldReceive('updateSupplierPurchasePrice')
            ->once()
            ->andThrow(new DatabaseOperationFailedException(
                operation: 'updateSupplierPurchasePrice',
                reason: 'Connection failed',
            ));

        $this->logger
            ->shouldReceive('warning')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => \array_key_exists('sku', $context));

        // No exception should propagate
        $result = $this->useCase->execute($commands);

        self::assertInstanceOf(CostPriceUpdateResult::class, $result);
    }

    #[Test]
    public function it_deduplicates_supplier_lookup_per_unique_sku(): void
    {
        $sku = Sku::fromTrusted('SKU-001');
        $supplier = new ProductSupplier(supplierName: 'AcmeCo', purchasePrice: 10.0, isDefault: true);
        $supplierGuid = new Guid('00000000-0000-0000-0000-000000000001');

        // Two commands with the same SKU
        $commands = [
            new UpdateCostPriceCommand(sku: $sku, costPrice: Money::exclusive(10.50), supplierName: 'AcmeCo'),
            new UpdateCostPriceCommand(sku: $sku, costPrice: Money::exclusive(15.00), supplierName: 'AcmeCo'),
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
            ->andReturn(['SKU-001' => new Guid('10000000-0000-0000-0000-000000000001')]);

        $this->supplierGuidResolver
            ->shouldReceive('resolve')
            ->once()
            ->andReturn($supplierGuid);

        $this->inventoryUpdateClient
            ->shouldReceive('updateBulkSupplierStats')
            ->once();

        $this->stockItemRepository
            ->shouldReceive('updateSupplierPurchasePrice')
            ->twice();

        $this->useCase->execute($commands);
    }
}
