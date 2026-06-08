<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Jobs\Linnworks;

use App\Application\Contracts\Catalog\CostPriceChangeLogRepositoryInterface;
use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Application\Contracts\Linnworks\LinnworksSyncDispatcherInterface;
use App\Application\Contracts\Linnworks\StockItemSupplierRepositoryInterface;
use App\Application\Linnworks\Resolvers\SupplierGuidResolver;
use App\Application\Linnworks\UpdateCostPriceBySupplier\UpdateCostPriceBySupplierUseCase;
use App\Domain\Catalog\Product\Commands\UpdateCostPriceCommand;
use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Inventory\ValueObjects\StockItemSupplierStat;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Jobs\Linnworks\UpdateCostPriceBatchJob;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * The use case is final (not mockable), so each scenario drives the real
 * UpdateCostPriceBySupplierUseCase with mocked interface boundaries and asserts only
 * the job's own branching: re-throw on a whole-batch write outage, stay quiet on success,
 * and fail-fast (no propagation) on a permanent bad-link validation error.
 */
#[CoversClass(UpdateCostPriceBatchJob::class)]
final class UpdateCostPriceBatchJobTest extends TestCase
{
    private InventoryClientInterface&MockInterface $inventoryClient;

    private InventoryUpdateClientInterface&MockInterface $inventoryUpdateClient;

    private StockItemSupplierRepositoryInterface&MockInterface $supplierRepository;

    private SupplierGuidResolver&MockInterface $supplierGuidResolver;

    private LinnworksSyncDispatcherInterface&MockInterface $syncDispatcher;

    private UpdateCostPriceBySupplierUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->inventoryClient = Mockery::mock(InventoryClientInterface::class);
        $this->inventoryUpdateClient = Mockery::mock(InventoryUpdateClientInterface::class);
        $this->supplierRepository = Mockery::mock(StockItemSupplierRepositoryInterface::class);
        $this->supplierGuidResolver = Mockery::mock(SupplierGuidResolver::class);
        $this->syncDispatcher = Mockery::mock(LinnworksSyncDispatcherInterface::class);
        $this->syncDispatcher->shouldReceive('dispatchStockItemSync')->byDefault();

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->byDefault();
        $logger->shouldReceive('warning')->byDefault();
        $logger->shouldReceive('error')->byDefault();

        $changeLogRepository = Mockery::mock(CostPriceChangeLogRepositoryInterface::class);
        $changeLogRepository->shouldReceive('record')->byDefault();

        $this->useCase = new UpdateCostPriceBySupplierUseCase(
            $this->inventoryClient,
            $this->inventoryUpdateClient,
            $this->supplierRepository,
            $this->supplierGuidResolver,
            $this->syncDispatcher,
            $logger,
            $changeLogRepository,
        );
    }

    #[Test]
    public function it_rethrows_a_transient_outage_when_the_whole_batch_write_fails(): void
    {
        $this->arrangeResolvableBatch();
        $this->inventoryUpdateClient
            ->shouldReceive('updateStockSupplierStats')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Linnworks'));
        $this->supplierRepository->shouldNotReceive('bulkUpdatePurchasePrices');

        $job = new UpdateCostPriceBatchJob('AcmeCo', $this->commands());

        $this->expectException(ExternalServiceUnavailableException::class);

        $job->handle($this->useCase);
    }

    #[Test]
    public function it_completes_without_throwing_when_the_batch_succeeds(): void
    {
        $this->arrangeResolvableBatch();
        $this->inventoryUpdateClient->shouldReceive('updateStockSupplierStats')->once();
        $this->supplierRepository->shouldReceive('bulkUpdatePurchasePrices')->once();

        $job = new UpdateCostPriceBatchJob('AcmeCo', $this->commands());

        // A successful (or partially-successful) batch must NOT trigger a retry — the absence of a
        // thrown exception, together with the once() expectations, is the assertion.
        $job->handle($this->useCase);
    }

    #[Test]
    public function it_does_not_propagate_a_permanent_bad_supplier_link(): void
    {
        // SKU-001 is not linked to AcmeCo → the use case throws ValidationFailedException pre-flight.
        // The job swallows it via fail() (a no-op here: no queue context in a unit test), so handle()
        // returns without re-throwing — proving the permanent error won't burn the retry budget.
        $this->supplierRepository
            ->shouldReceive('getSuppliersBySkus')
            ->once()
            ->andReturn(['SKU-001' => []]);
        $this->inventoryClient->shouldNotReceive('resolveStockItemIds');

        $job = new UpdateCostPriceBatchJob('AcmeCo', $this->commands());

        $job->handle($this->useCase);
    }

    /**
     * @return non-empty-list<UpdateCostPriceCommand>
     */
    private function commands(): array
    {
        return [new UpdateCostPriceCommand(Sku::fromTrusted('SKU-001'), Money::exclusive(10.50))];
    }

    /**
     * Arrange the mocks so SKU-001 resolves and merges cleanly up to the bulk write.
     */
    private function arrangeResolvableBatch(): void
    {
        $supplier = new ProductSupplier(supplierName: 'AcmeCo', purchasePrice: Money::exclusive(10.0), isDefault: true);
        $supplierGuid = new Guid('00000000-0000-0000-0000-000000000001');
        $stockId = '10000000-0000-0000-0000-000000000001';

        $this->supplierRepository->shouldReceive('getSuppliersBySkus')->once()
            ->andReturn(['SKU-001' => [$supplier]]);
        $this->inventoryClient->shouldReceive('resolveStockItemIds')->once()
            ->andReturn(['SKU-001' => new Guid($stockId)]);
        $this->supplierGuidResolver->shouldReceive('resolve')->once()->andReturn($supplierGuid);
        $this->inventoryClient->shouldReceive('getStockSupplierStatsBulk')->once()
            ->andReturn([$stockId => [$this->makeSupplierStat($stockId, $supplierGuid)]]);
    }

    private function makeSupplierStat(string $stockItemId, Guid $supplierGuid): StockItemSupplierStat
    {
        return new StockItemSupplierStat(
            stockItemId: new Guid($stockItemId),
            stockItemIntId: null,
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
            averageLeadTime: null,
            supplierMinOrderQty: null,
            supplierPackSize: null,
        );
    }
}
