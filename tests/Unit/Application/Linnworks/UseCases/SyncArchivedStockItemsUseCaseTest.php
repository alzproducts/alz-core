<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Linnworks\UseCases;

use App\Application\Contracts\Linnworks\StockDashboardsClientInterface;
use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Application\Linnworks\UseCases\SyncArchivedStockItemsUseCase;
use App\Application\Results\SaveManyResult;
use App\Domain\Inventory\ValueObjects\Dimensions;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Domain\Inventory\ValueObjects\Weight;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Unit tests for the archived stock item sync orchestration.
 *
 * The use case is deliberately thin — its responsibilities are:
 * 1. Fetch rows via the dashboards client
 * 2. Short-circuit on an empty result
 * 3. Upsert via the repository
 * 4. Log the outcome with counts
 *
 * These tests pin that behaviour with tight assertions so mutation testing
 * can't silently relax the contract.
 */
#[CoversClass(SyncArchivedStockItemsUseCase::class)]
final class SyncArchivedStockItemsUseCaseTest extends TestCase
{
    private StockDashboardsClientInterface&MockInterface $stockDashboardsClient;

    private StockItemRepositoryInterface&MockInterface $stockItemRepository;

    private LoggerInterface&MockInterface $logger;

    private SyncArchivedStockItemsUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockDashboardsClient = Mockery::mock(StockDashboardsClientInterface::class);
        $this->stockItemRepository = Mockery::mock(StockItemRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new SyncArchivedStockItemsUseCase(
            $this->stockDashboardsClient,
            $this->stockItemRepository,
            $this->logger,
        );
    }

    #[Test]
    public function execute_short_circuits_when_dashboards_returns_no_rows(): void
    {
        $this->stockDashboardsClient
            ->shouldReceive('getArchivedStockItemsFull')
            ->once()
            ->andReturn([]);

        $this->stockItemRepository
            ->shouldNotReceive('upsertArchivedStockItems');

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Starting archived stock items sync');
        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('No archived stock items found');

        $this->useCase->execute();
    }

    #[Test]
    public function execute_passes_fetched_items_straight_through_to_the_repository(): void
    {
        $items = [
            $this->createStockItem('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'SKU-A'),
            $this->createStockItem('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'SKU-B'),
            $this->createStockItem('cccccccc-cccc-cccc-cccc-cccccccccccc', 'SKU-C'),
        ];

        $this->stockDashboardsClient
            ->shouldReceive('getArchivedStockItemsFull')
            ->once()
            ->andReturn($items);

        $this->stockItemRepository
            ->shouldReceive('upsertArchivedStockItems')
            ->once()
            ->with($items)
            ->andReturn(new SaveManyResult(succeeded: 3, failed: 0));

        $this->logger->shouldReceive('info')->with('Starting archived stock items sync');
        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with(
                'Completed archived stock items sync',
                Mockery::on(
                    static fn(array $context): bool => $context['total_fetched'] === 3
                        && $context['succeeded'] === 3
                        && $context['failed'] === 0,
                ),
            );

        $this->useCase->execute();
    }

    #[Test]
    public function execute_logs_partial_failure_counts_from_repository_result(): void
    {
        $items = [
            $this->createStockItem('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'SKU-A'),
            $this->createStockItem('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'SKU-B'),
        ];

        $this->stockDashboardsClient
            ->shouldReceive('getArchivedStockItemsFull')
            ->once()
            ->andReturn($items);

        $this->stockItemRepository
            ->shouldReceive('upsertArchivedStockItems')
            ->once()
            ->andReturn(new SaveManyResult(
                succeeded: 1,
                failed: 1,
                failedReferences: ['bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'],
            ));

        $this->logger->shouldReceive('info')->with('Starting archived stock items sync');
        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with(
                'Completed archived stock items sync',
                Mockery::on(
                    static fn(array $context): bool => $context['total_fetched'] === 2
                        && $context['succeeded'] === 1
                        && $context['failed'] === 1,
                ),
            );

        $this->useCase->execute();
    }

    private function createStockItem(string $stockItemId, string $sku): StockItemFull
    {
        return new StockItemFull(
            stockItemId: $stockItemId,
            sku: $sku,
            title: "Item {$sku}",
            barcode: '',
            quantity: 0,
            available: 0,
            inOrder: 0,
            due: 0,
            minimumLevel: 0,
            jit: false,
            purchasePrice: 0.0,
            retailPrice: 0.0,
            taxRate: null,
            weight: Weight::zero(),
            dimensions: Dimensions::zero(),
            isComposite: false,
            categoryId: '00000000-0000-0000-0000-000000000000',
            categoryName: 'Default',
        );
    }
}
