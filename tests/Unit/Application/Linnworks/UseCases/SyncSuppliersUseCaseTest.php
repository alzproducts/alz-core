<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Linnworks\UseCases;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\SupplierRepositoryInterface;
use App\Application\Linnworks\UseCases\SyncSuppliersUseCase;
use App\Application\Results\SaveManyResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Inventory\ValueObjects\Supplier;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * SyncSuppliersUseCase Unit Tests.
 *
 * Tests supplier directory sync orchestration:
 * - Empty supplier list handling
 * - Happy path with upsert + reconciliation
 * - API exceptions bubble through
 */
#[CoversClass(SyncSuppliersUseCase::class)]
final class SyncSuppliersUseCaseTest extends TestCase
{
    private InventoryClientInterface&MockInterface $inventoryClient;

    private SupplierRepositoryInterface&MockInterface $supplierRepository;

    private LoggerInterface&MockInterface $logger;

    private SyncSuppliersUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventoryClient = Mockery::mock(InventoryClientInterface::class);
        $this->supplierRepository = Mockery::mock(SupplierRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new SyncSuppliersUseCase(
            $this->inventoryClient,
            $this->supplierRepository,
            $this->logger,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Empty Suppliers Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_returns_empty_result_when_no_suppliers_found(): void
    {
        $this->inventoryClient
            ->shouldReceive('getSuppliers')
            ->once()
            ->andReturn([]);

        $this->supplierRepository->shouldNotReceive('saveSuppliersBulk');
        $this->supplierRepository->shouldNotReceive('deleteWhereNotIn');

        $this->logger->shouldReceive('info')->once()->with('Starting supplier directory sync from Linnworks');
        $this->logger->shouldReceive('info')->once()->with('Supplier directory sync completed: no suppliers found in Linnworks');

        $result = $this->useCase->execute();

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->saved);
        $this->assertSame(0, $result->failed);
    }

    /*
    |--------------------------------------------------------------------------
    | Happy Path
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_upserts_suppliers_and_reconciles_stale_records(): void
    {
        $suppliers = [
            $this->createSupplier('id-1', 'Supplier One'),
            $this->createSupplier('id-2', 'Supplier Two'),
        ];

        $this->inventoryClient
            ->shouldReceive('getSuppliers')
            ->once()
            ->andReturn($suppliers);

        $this->supplierRepository
            ->shouldReceive('saveSuppliersBulk')
            ->once()
            ->with($suppliers)
            ->andReturn(new SaveManyResult(succeeded: 2, failed: 0, failedReferences: []));

        $this->supplierRepository
            ->shouldReceive('deleteWhereNotIn')
            ->once()
            ->with(['id-1', 'id-2'])
            ->andReturn(1);

        $this->logger->shouldReceive('info')->with('Starting supplier directory sync from Linnworks');
        $this->logger->shouldReceive('info')->with('Reconciled stale suppliers', Mockery::type('array'));
        $this->logger->shouldReceive('info')->with('Supplier directory sync completed', Mockery::on(
            static fn(array $context): bool => $context['fetched'] === 2
                && $context['saved'] === 2
                && $context['failed'] === 0
                && $context['deleted'] === 1,
        ));

        $result = $this->useCase->execute();

        $this->assertSame(2, $result->fetched);
        $this->assertSame(2, $result->saved);
        $this->assertSame(0, $result->failed);
        $this->assertTrue($result->allSaved());
    }

    #[Test]
    public function execute_does_not_log_reconciliation_when_no_stale_records(): void
    {
        $suppliers = [$this->createSupplier('id-1', 'Supplier One')];

        $this->inventoryClient
            ->shouldReceive('getSuppliers')
            ->once()
            ->andReturn($suppliers);

        $this->supplierRepository
            ->shouldReceive('saveSuppliersBulk')
            ->once()
            ->andReturn(new SaveManyResult(succeeded: 1, failed: 0, failedReferences: []));

        $this->supplierRepository
            ->shouldReceive('deleteWhereNotIn')
            ->once()
            ->with(['id-1'])
            ->andReturn(0);

        $this->logger->shouldReceive('info')->with('Starting supplier directory sync from Linnworks');
        $this->logger->shouldNotReceive('info')->with('Reconciled stale suppliers', Mockery::any());
        $this->logger->shouldReceive('info')->with('Supplier directory sync completed', Mockery::type('array'));

        $result = $this->useCase->execute();

        $this->assertSame(1, $result->fetched);
        $this->assertSame(1, $result->saved);
    }

    /*
    |--------------------------------------------------------------------------
    | Exception Bubbling
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_bubbles_api_exceptions(): void
    {
        $this->inventoryClient
            ->shouldReceive('getSuppliers')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Linnworks', 60));

        $this->logger->shouldReceive('info')->with('Starting supplier directory sync from Linnworks');

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    private function createSupplier(string $id, string $name): Supplier
    {
        return new Supplier(
            pkSupplierId: $id,
            supplierName: $name,
            contactName: null,
            address: null,
            alternativeAddress: null,
            city: null,
            region: null,
            country: null,
            postCode: null,
            telephoneNumber: null,
            secondaryTelNumber: null,
            faxNumber: null,
            email: null,
            webPage: null,
            currency: null,
        );
    }
}
