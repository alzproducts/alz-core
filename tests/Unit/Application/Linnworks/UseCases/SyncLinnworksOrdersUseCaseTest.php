<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Linnworks\UseCases;

use App\Application\Contracts\Linnworks\LinnworksOrderRepositoryInterface;
use App\Application\Contracts\Linnworks\OrderClientInterface;
use App\Application\Linnworks\UseCases\SyncLinnworksOrdersUseCase;
use App\Application\Results\SaveManyResult;
use App\Domain\Linnworks\ValueObjects\LinnworksOrder;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Generator;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * SyncLinnworksOrdersUseCase Unit Tests.
 *
 * Tests generator-based order sync orchestration:
 * - Empty orders handling
 * - Buffer management (flush every 5 pages)
 * - Continue-on-failure semantics
 * - Final buffer flush
 * - Max LastUpdated tracking across batches
 */
#[CoversClass(SyncLinnworksOrdersUseCase::class)]
final class SyncLinnworksOrdersUseCaseTest extends TestCase
{
    private const string TEST_FROM = '2026-03-19 10:00:00';

    private OrderClientInterface&MockInterface $orderClient;

    private LinnworksOrderRepositoryInterface&MockInterface $orderRepository;

    private LoggerInterface&MockInterface $logger;

    private SyncLinnworksOrdersUseCase $useCase;

    private DateTimeImmutable $fromDate;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->orderClient = Mockery::mock(OrderClientInterface::class);
        $this->orderRepository = Mockery::mock(LinnworksOrderRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new SyncLinnworksOrdersUseCase(
            $this->orderClient,
            $this->orderRepository,
            $this->logger,
        );

        $this->fromDate = new DateTimeImmutable(self::TEST_FROM);
    }

    /*
    |--------------------------------------------------------------------------
    | Empty Orders Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_returns_empty_result_when_no_orders_found(): void
    {
        $this->orderClient
            ->shouldReceive('iterateOrders')
            ->once()
            ->with($this->fromDate)
            ->andReturn($this->emptyGenerator());

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Linnworks order sync starting', Mockery::type('array'));
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Linnworks order sync completed: no orders found', Mockery::type('array'));

        $result = $this->useCase->execute($this->fromDate);

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->saved);
        $this->assertSame(0, $result->failed);
        $this->assertNull($result->latestLastUpdated);
    }

    /*
    |--------------------------------------------------------------------------
    | Single Page Branch (No Buffer Flush, Only Final Flush)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_flushes_remaining_buffer_when_less_than_batch_size(): void
    {
        $orders = [
            $this->createOrder('00000000-0000-0000-0000-000000000001', '2026-03-19 11:00:00'),
            $this->createOrder('00000000-0000-0000-0000-000000000002', '2026-03-19 12:00:00'),
        ];

        $this->orderClient
            ->shouldReceive('iterateOrders')
            ->once()
            ->with($this->fromDate)
            ->andReturn($this->singlePageGenerator($orders));

        $this->orderRepository
            ->shouldReceive('saveMany')
            ->once()
            ->with($orders)
            ->andReturn(SaveManyResult::success(2));

        $this->logger->shouldReceive('info')->with('Linnworks order sync starting', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->with('Fetched order page from API', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->with('Flushing order batch to database', Mockery::type('array'));
        $this->logger->shouldReceive('info')->with('Linnworks order sync completed', Mockery::type('array'));

        $result = $this->useCase->execute($this->fromDate);

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
        $itemsPerPage = [];
        for ($i = 0; $i < 5; $i++) {
            $itemsPerPage[$i] = [
                $this->createOrder(
                    \sprintf('00000000-0000-0000-0000-%012d', $i * 2 + 1),
                    \sprintf('2026-03-19 %02d:00:00', 10 + $i),
                ),
                $this->createOrder(
                    \sprintf('00000000-0000-0000-0000-%012d', $i * 2 + 2),
                    \sprintf('2026-03-19 %02d:30:00', 10 + $i),
                ),
            ];
        }

        $this->orderClient
            ->shouldReceive('iterateOrders')
            ->once()
            ->andReturn($this->multiPageGenerator($itemsPerPage));

        // Should flush once after 5 pages (10 orders)
        $this->orderRepository
            ->shouldReceive('saveMany')
            ->once()
            ->with(Mockery::on(static fn(array $items) => \count($items) === 10))
            ->andReturn(SaveManyResult::success(10));

        $this->logger->shouldReceive('info')->with('Linnworks order sync starting', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->times(5)->with('Fetched order page from API', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->once()->with('Flushing order batch to database', Mockery::type('array'));
        $this->logger->shouldReceive('info')->with('Linnworks order sync completed', Mockery::type('array'));

        $result = $this->useCase->execute($this->fromDate);

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
        $orders = [
            $this->createOrder('00000000-0000-0000-0000-000000000001', '2026-03-19 11:00:00'),
            $this->createOrder('00000000-0000-0000-0000-000000000002', '2026-03-19 12:00:00'),
            $this->createOrder('00000000-0000-0000-0000-000000000003', '2026-03-19 13:00:00'),
        ];
        $failedRefs = ['00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000003'];

        $this->orderClient
            ->shouldReceive('iterateOrders')
            ->once()
            ->andReturn($this->singlePageGenerator($orders));

        $this->orderRepository
            ->shouldReceive('saveMany')
            ->once()
            ->andReturn(new SaveManyResult(succeeded: 1, failed: 2, failedReferences: $failedRefs));

        $this->logger->shouldReceive('info')->with('Linnworks order sync starting', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->with('Fetched order page from API', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->with('Flushing order batch to database', Mockery::type('array'));
        $this->logger
            ->shouldReceive('error')
            ->once()
            ->with('Failed to save some orders to database', Mockery::on(
                static fn(array $context) => $context['failed_count'] === 2
                    && $context['failed_ids'] === $failedRefs,
            ));
        $this->logger->shouldReceive('info')->with('Linnworks order sync completed', Mockery::type('array'));

        $result = $this->useCase->execute($this->fromDate);

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
            $itemsPerPage[$i] = [
                $this->createOrder(
                    \sprintf('00000000-0000-0000-0000-%012d', $i + 1),
                    \sprintf('2026-03-19 %02d:00:00', 10 + $i),
                ),
            ];
        }

        $this->orderClient
            ->shouldReceive('iterateOrders')
            ->once()
            ->andReturn($this->multiPageGenerator($itemsPerPage));

        // First flush after 5 pages (5 orders)
        $this->orderRepository
            ->shouldReceive('saveMany')
            ->once()
            ->with(Mockery::on(static fn(array $items) => \count($items) === 5))
            ->andReturn(SaveManyResult::success(5));

        // Final flush with remaining 2 orders
        $this->orderRepository
            ->shouldReceive('saveMany')
            ->once()
            ->with(Mockery::on(static fn(array $items) => \count($items) === 2))
            ->andReturn(SaveManyResult::success(2));

        $this->logger->shouldReceive('info')->with('Linnworks order sync starting', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->times(7)->with('Fetched order page from API', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->times(2)->with('Flushing order batch to database', Mockery::type('array'));
        $this->logger->shouldReceive('info')->with('Linnworks order sync completed', Mockery::type('array'));

        $result = $this->useCase->execute($this->fromDate);

        $this->assertSame(7, $result->fetched);
        $this->assertSame(7, $result->saved);
        $this->assertSame(0, $result->failed);
    }

    /*
    |--------------------------------------------------------------------------
    | Max LastUpdated Tracking
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_tracks_max_last_updated_across_pages(): void
    {
        $expectedLatest = new DateTimeImmutable('2026-03-19 15:00:00');

        $page1 = [
            $this->createOrder('00000000-0000-0000-0000-000000000001', '2026-03-19 11:00:00'),
            $this->createOrder('00000000-0000-0000-0000-000000000002', '2026-03-19 15:00:00'), // max
        ];
        $page2 = [
            $this->createOrder('00000000-0000-0000-0000-000000000003', '2026-03-19 13:00:00'),
            $this->createOrder('00000000-0000-0000-0000-000000000004', '2026-03-19 14:00:00'),
        ];

        $this->orderClient
            ->shouldReceive('iterateOrders')
            ->once()
            ->andReturn($this->multiPageGenerator([0 => $page1, 1 => $page2]));

        $this->orderRepository
            ->shouldReceive('saveMany')
            ->once()
            ->andReturn(SaveManyResult::success(4));

        $this->logger->shouldReceive('info')->with('Linnworks order sync starting', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->times(2)->with('Fetched order page from API', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->once()->with('Flushing order batch to database', Mockery::type('array'));
        $this->logger->shouldReceive('info')->with('Linnworks order sync completed', Mockery::type('array'));

        $result = $this->useCase->execute($this->fromDate);

        $this->assertSame(4, $result->fetched);
        $this->assertEquals($expectedLatest, $result->latestLastUpdated);
    }

    #[Test]
    public function execute_returns_null_latest_last_updated_when_no_orders(): void
    {
        $this->orderClient
            ->shouldReceive('iterateOrders')
            ->once()
            ->andReturn($this->emptyGenerator());

        $this->logger->shouldReceive('info')->with('Linnworks order sync starting', Mockery::type('array'));
        $this->logger->shouldReceive('info')->with('Linnworks order sync completed: no orders found', Mockery::type('array'));

        $result = $this->useCase->execute($this->fromDate);

        $this->assertNull($result->latestLastUpdated);
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
        $itemsPerPage = [];
        for ($i = 0; $i < 25; $i++) {
            $itemsPerPage[$i] = [
                $this->createOrder(
                    \sprintf('00000000-0000-0000-0000-%012d', $i + 1),
                    \sprintf('2026-03-19 %02d:%02d:00', 10 + (int) ($i / 6), ($i * 10) % 60),
                ),
            ];
        }

        $this->orderClient
            ->shouldReceive('iterateOrders')
            ->once()
            ->andReturn($this->multiPageGenerator($itemsPerPage));

        // 5 flushes (one per batch of 5 pages)
        $this->orderRepository
            ->shouldReceive('saveMany')
            ->times(5)
            ->with(Mockery::on(static fn(array $items) => \count($items) === 5))
            ->andReturn(SaveManyResult::success(5));

        $this->logger->shouldReceive('info')->with('Linnworks order sync starting', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->times(25)->with('Fetched order page from API', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->times(5)->with('Flushing order batch to database', Mockery::type('array'));

        // Progress log after every 5 batches (25 pages / 5 pages per batch = 5 batches -> 1 progress log)
        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Linnworks order sync progress', Mockery::type('array'));

        $this->logger->shouldReceive('info')->with('Linnworks order sync completed', Mockery::type('array'));

        $result = $this->useCase->execute($this->fromDate);

        $this->assertSame(25, $result->fetched);
        $this->assertSame(25, $result->saved);
    }

    /*
    |--------------------------------------------------------------------------
    | Test Fixtures & Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @return Generator<int, list<LinnworksOrder>, mixed, void>
     */
    private function emptyGenerator(): Generator
    {
        yield from [];
    }

    /**
     * @param list<LinnworksOrder> $orders
     *
     * @return Generator<int, list<LinnworksOrder>, mixed, void>
     */
    private function singlePageGenerator(array $orders): Generator
    {
        yield 1 => $orders;
    }

    /**
     * @param array<int, list<LinnworksOrder>> $ordersPerPage Page index => orders
     *
     * @return Generator<int, list<LinnworksOrder>, mixed, void>
     */
    private function multiPageGenerator(array $ordersPerPage): Generator
    {
        foreach ($ordersPerPage as $pageIndex => $orders) {
            yield $pageIndex + 1 => $orders;
        }
    }

    private function createOrder(string $orderId, string $lastUpdated): LinnworksOrder
    {
        return new LinnworksOrder(
            orderId: Guid::fromTrusted($orderId),
            numOrderId: IntId::fromTrusted(1000 + (int) \mb_substr($orderId, -1)),
            processed: true,
            lastUpdated: new DateTimeImmutable($lastUpdated),
            referenceNum: "REF-{$orderId}",
            externalReferenceNum: '',
            status: 1,
            isCancelled: false,
            fulfilmentLocationId: '00000000-0000-0000-0000-000000000000',
            source: 'SHOPWIRED',
            subSource: 'web',
            totalCharge: 29.99,
            subtotal: 24.99,
            tax: 5.00,
            paymentMethod: 'PayPal',
            paymentMethodId: Guid::fromTrusted('00000000-0000-0000-0000-000000000099'),
            currency: 'GBP',
            postalServiceName: 'Royal Mail',
            vendor: 'Royal Mail',
            trackingNumber: '',
            postageCost: 3.99,
            postageCostExTax: 3.33,
            channelBuyerName: 'Test Buyer',
            shipEmail: 'test@example.com',
            shipFullName: 'Test Buyer',
            shipCompany: '',
            shipAddress1: '123 Test St',
            shipAddress2: '',
            shipAddress3: '',
            shipTown: 'London',
            shipPostcode: 'SW1A 1AA',
            shipCountry: 'United Kingdom',
            billFullName: 'Test Buyer',
            billCompany: '',
            billAddress1: '123 Test St',
            billAddress2: '',
            billAddress3: '',
            billTown: 'London',
            billPostcode: 'SW1A 1AA',
            billCountry: 'United Kingdom',
        );
    }
}
