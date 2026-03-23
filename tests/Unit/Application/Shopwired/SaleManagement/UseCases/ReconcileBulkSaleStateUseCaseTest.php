<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\SaleManagement\UseCases;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\SaleReconciliationDispatcherInterface;
use App\Application\Shopwired\SaleManagement\UseCases\ReconcileBulkSaleStateUseCase;
use App\Domain\ValueObjects\IntId;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(ReconcileBulkSaleStateUseCase::class)]
final class ReconcileBulkSaleStateUseCaseTest extends TestCase
{
    private const int SALE_CATEGORY_ID = 999;

    private ProductRepositoryInterface&MockInterface $productRepo;

    private SaleReconciliationDispatcherInterface&MockInterface $dispatcher;

    private LoggerInterface&MockInterface $logger;

    private ReconcileBulkSaleStateUseCase $useCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->productRepo = Mockery::mock(ProductRepositoryInterface::class);
        $this->dispatcher = Mockery::mock(SaleReconciliationDispatcherInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new ReconcileBulkSaleStateUseCase(
            productRepo: $this->productRepo,
            dispatcher: $this->dispatcher,
            logger: $this->logger,
            saleCategoryId: self::SALE_CATEGORY_ID,
        );
    }

    // ========================================================================
    // No Drifted Products
    // ========================================================================

    #[Test]
    public function does_not_dispatch_when_no_products_have_sale_state_drift(): void
    {
        $this->productRepo->shouldReceive('getAllProductsWithSaleStateDrift')
            ->once()
            ->with(self::SALE_CATEGORY_ID)
            ->andReturn([]);

        $this->dispatcher->shouldNotReceive('dispatchReconciliation');

        $this->useCase->execute();
    }

    // ========================================================================
    // Multiple Drifted Products
    // ========================================================================

    #[Test]
    public function dispatches_reconciliation_for_each_drifted_product(): void
    {
        $this->productRepo->shouldReceive('getAllProductsWithSaleStateDrift')
            ->once()
            ->with(self::SALE_CATEGORY_ID)
            ->andReturn([101, 202, 303]);

        $this->dispatcher->shouldReceive('dispatchReconciliation')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 101), null);

        $this->dispatcher->shouldReceive('dispatchReconciliation')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 202), null);

        $this->dispatcher->shouldReceive('dispatchReconciliation')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 303), null);

        $this->useCase->execute();
    }

    // ========================================================================
    // Single Drifted Product
    // ========================================================================

    #[Test]
    public function dispatches_once_for_single_drifted_product(): void
    {
        $this->productRepo->shouldReceive('getAllProductsWithSaleStateDrift')
            ->once()
            ->with(self::SALE_CATEGORY_ID)
            ->andReturn([42]);

        $this->dispatcher->shouldReceive('dispatchReconciliation')
            ->once()
            ->with(Mockery::on(static fn(IntId $id): bool => $id->value === 42), null);

        $this->useCase->execute();
    }
}
