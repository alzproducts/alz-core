<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Linnworks\UseCases;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Application\Linnworks\UseCases\SyncAllStockItemsUseCase;
use App\Application\ValueObjects\SaveManyResult;
use App\Domain\Inventory\ValueObjects\Dimensions;
use App\Domain\Inventory\ValueObjects\StockItem;
use App\Domain\Inventory\ValueObjects\Weight;
use Generator;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * SyncAllStockItemsUseCase Unit Tests.
 *
 * Tests generator-based stock item sync orchestration:
 * - Empty stock items handling
 * - Buffer management (flush every 5 pages)
 * - Continue-on-failure semantics
 * - Final buffer flush
 */
#[CoversClass(SyncAllStockItemsUseCase::class)]
final class SyncAllStockItemsUseCaseTest extends TestCase
{
    private InventoryClientInterface&MockInterface $inventoryClient;

    private StockItemRepositoryInterface&MockInterface $stockItemRepository;

    private LoggerInterface&MockInterface $logger;

    private SyncAllStockItemsUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventoryClient = Mockery::mock(InventoryClientInterface::class);
        $this->stockItemRepository = Mockery::mock(StockItemRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new SyncAllStockItemsUseCase(
            $this->inventoryClient,
            $this->stockItemRepository,
            $this->logger,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Empty Stock Items Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_returns_empty_result_when_no_stock_items_found(): void
    {
        $this->inventoryClient
            ->shouldReceive('iterateStockItemBatches')
            ->once()
            ->andReturn($this->emptyGenerator());

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Starting full stock item sync from Linnworks');

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Stock item sync completed: no items found in Linnworks');

        $result = $this->useCase->execute();

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->saved);
        $this->assertSame(0, $result->failed);
    }

    /*
    |--------------------------------------------------------------------------
    | Single Page Branch (No Buffer Flush, Only Final Flush)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_flushes_remaining_buffer_when_less_than_batch_size(): void
    {
        $stockItems = [$this->createStockItem('item-1'), $this->createStockItem('item-2')];

        $this->inventoryClient
            ->shouldReceive('iterateStockItemBatches')
            ->once()
            ->andReturn($this->singlePageGenerator($stockItems));

        $this->stockItemRepository
            ->shouldReceive('saveMany')
            ->once()
            ->with($stockItems)
            ->andReturn(SaveManyResult::success(2));

        $this->logger->shouldReceive('info')->with('Starting full stock item sync from Linnworks');
        $this->logger->shouldReceive('debug')->with('Fetched stock item page from API', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->with('Flushing stock item batch to database', Mockery::type('array'));
        $this->logger->shouldReceive('info')->with('Stock item sync completed', Mockery::type('array'));

        $result = $this->useCase->execute();

        $this->assertSame(2, $result->fetched);
        $this->assertSame(2, $result->saved);
        $this->assertSame(0, $result->failed);
        $this->assertTrue($result->allSaved());
    }

    /*
    |--------------------------------------------------------------------------
    | Buffer Flush Branch (5+ Pages)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_flushes_buffer_after_5_pages(): void
    {
        // Create 5 pages with 2 items each = 10 items total
        $itemsPerPage = [];
        for ($i = 0; $i < 5; $i++) {
            $itemsPerPage[$i] = [
                $this->createStockItem("item-{$i}-1"),
                $this->createStockItem("item-{$i}-2"),
            ];
        }

        $this->inventoryClient
            ->shouldReceive('iterateStockItemBatches')
            ->once()
            ->andReturn($this->multiPageGenerator($itemsPerPage));

        // Should flush once after 5 pages (10 items)
        $this->stockItemRepository
            ->shouldReceive('saveMany')
            ->once()
            ->with(Mockery::on(static fn(array $items) => \count($items) === 10))
            ->andReturn(SaveManyResult::success(10));

        $this->logger->shouldReceive('info')->with('Starting full stock item sync from Linnworks');
        $this->logger->shouldReceive('debug')->times(5)->with('Fetched stock item page from API', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->once()->with('Flushing stock item batch to database', Mockery::type('array'));
        $this->logger->shouldReceive('info')->with('Stock item sync completed', Mockery::type('array'));

        $result = $this->useCase->execute();

        $this->assertSame(10, $result->fetched);
        $this->assertSame(10, $result->saved);
        $this->assertSame(0, $result->failed);
    }

    /*
    |--------------------------------------------------------------------------
    | Partial Failure Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_continues_on_partial_failure_and_logs_error(): void
    {
        $stockItems = [
            $this->createStockItem('item-1'),
            $this->createStockItem('item-2'),
            $this->createStockItem('item-3'),
        ];
        $failedRefs = ['item-2', 'item-3'];

        $this->inventoryClient
            ->shouldReceive('iterateStockItemBatches')
            ->once()
            ->andReturn($this->singlePageGenerator($stockItems));

        $this->stockItemRepository
            ->shouldReceive('saveMany')
            ->once()
            ->andReturn(new SaveManyResult(succeeded: 1, failed: 2, failedReferences: $failedRefs));

        $this->logger->shouldReceive('info')->with('Starting full stock item sync from Linnworks');
        $this->logger->shouldReceive('debug')->with('Fetched stock item page from API', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->with('Flushing stock item batch to database', Mockery::type('array'));
        $this->logger
            ->shouldReceive('error')
            ->once()
            ->with('Failed to save some stock items to database', Mockery::on(
                static fn(array $context) => $context['failed_count'] === 2
                    && $context['failed_ids'] === $failedRefs,
            ));
        $this->logger->shouldReceive('info')->with('Stock item sync completed', Mockery::type('array'));

        $result = $this->useCase->execute();

        $this->assertSame(3, $result->fetched);
        $this->assertSame(1, $result->saved);
        $this->assertSame(2, $result->failed);
        $this->assertSame($failedRefs, $result->failedReferences);
        $this->assertTrue($result->hasFailures());
    }

    /*
    |--------------------------------------------------------------------------
    | Multiple Batches with Final Flush
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_handles_multiple_batches_plus_final_flush(): void
    {
        // Create 7 pages: first 5 pages flush at batch boundary, last 2 flush at end
        $itemsPerPage = [];
        for ($i = 0; $i < 7; $i++) {
            $itemsPerPage[$i] = [$this->createStockItem("item-{$i}")];
        }

        $this->inventoryClient
            ->shouldReceive('iterateStockItemBatches')
            ->once()
            ->andReturn($this->multiPageGenerator($itemsPerPage));

        // First flush after 5 pages (5 items)
        $this->stockItemRepository
            ->shouldReceive('saveMany')
            ->once()
            ->with(Mockery::on(static fn(array $items) => \count($items) === 5))
            ->andReturn(SaveManyResult::success(5));

        // Final flush with remaining 2 items
        $this->stockItemRepository
            ->shouldReceive('saveMany')
            ->once()
            ->with(Mockery::on(static fn(array $items) => \count($items) === 2))
            ->andReturn(SaveManyResult::success(2));

        $this->logger->shouldReceive('info')->with('Starting full stock item sync from Linnworks');
        $this->logger->shouldReceive('debug')->times(7)->with('Fetched stock item page from API', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->times(2)->with('Flushing stock item batch to database', Mockery::type('array'));
        $this->logger->shouldReceive('info')->with('Stock item sync completed', Mockery::type('array'));

        $result = $this->useCase->execute();

        $this->assertSame(7, $result->fetched);
        $this->assertSame(7, $result->saved);
        $this->assertSame(0, $result->failed);
    }

    /*
    |--------------------------------------------------------------------------
    | Progress Logging Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_logs_progress_every_5_batches(): void
    {
        // Create 25 pages = 5 batches of 5 pages each
        // Progress should be logged once (after batch 5, i.e., 25 pages)
        $itemsPerPage = [];
        for ($i = 0; $i < 25; $i++) {
            $itemsPerPage[$i] = [$this->createStockItem("item-{$i}")];
        }

        $this->inventoryClient
            ->shouldReceive('iterateStockItemBatches')
            ->once()
            ->andReturn($this->multiPageGenerator($itemsPerPage));

        // 5 flushes (one per batch of 5 pages)
        $this->stockItemRepository
            ->shouldReceive('saveMany')
            ->times(5)
            ->with(Mockery::on(static fn(array $items) => \count($items) === 5))
            ->andReturn(SaveManyResult::success(5));

        $this->logger->shouldReceive('info')->with('Starting full stock item sync from Linnworks');
        $this->logger->shouldReceive('debug')->times(25)->with('Fetched stock item page from API', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->times(5)->with('Flushing stock item batch to database', Mockery::type('array'));

        // Progress log after every 5 batches (25 pages / 5 pages per batch = 5 batches → 1 progress log)
        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Stock item sync progress', Mockery::type('array'));

        $this->logger->shouldReceive('info')->with('Stock item sync completed', Mockery::type('array'));

        $result = $this->useCase->execute();

        $this->assertSame(25, $result->fetched);
        $this->assertSame(25, $result->saved);
    }

    /*
    |--------------------------------------------------------------------------
    | Test Fixtures & Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @return Generator<int, list<StockItem>, mixed, void>
     */
    private function emptyGenerator(): Generator
    {
        yield from [];
    }

    /**
     * @param list<StockItem> $stockItems
     *
     * @return Generator<int, list<StockItem>, mixed, void>
     */
    private function singlePageGenerator(array $stockItems): Generator
    {
        yield 1 => $stockItems;
    }

    /**
     * @param array<int, list<StockItem>> $itemsPerPage Page index => items
     *
     * @return Generator<int, list<StockItem>, mixed, void>
     */
    private function multiPageGenerator(array $itemsPerPage): Generator
    {
        foreach ($itemsPerPage as $pageIndex => $items) {
            yield $pageIndex + 1 => $items;
        }
    }

    private function createStockItem(string $id): StockItem
    {
        return new StockItem(
            stockItemId: $id,
            sku: "SKU-{$id}",
            title: "Test Item {$id}",
            barcode: '',
            quantity: 100,
            available: 100,
            inOrder: 0,
            due: 0,
            minimumLevel: 0,
            purchasePrice: 10.0,
            retailPrice: 20.0,
            taxRate: null,
            weight: Weight::zero(),
            dimensions: Dimensions::zero(),
            isComposite: false,
            extendedProperties: [],
        );
    }
}
