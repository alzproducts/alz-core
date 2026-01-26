<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\OrderClientInterface;
use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Results\SaveManyResult;
use App\Application\Shopwired\UseCases\SyncOrdersUseCase;
use App\Domain\Catalog\Order\Enums\PreOrderStatus;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderAddress;
use App\Domain\Catalog\Order\ValueObjects\OrderCustomer;
use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Domain\Catalog\Order\ValueObjects\PaymentMethod;
use DateTimeImmutable;
use Generator;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * SyncOrdersUseCase Unit Tests.
 *
 * Tests generator-based order sync orchestration:
 * - Empty orders handling
 * - Buffer management (flush every 10 pages)
 * - Continue-on-failure semantics
 * - Final buffer flush
 */
#[CoversClass(SyncOrdersUseCase::class)]
final class SyncOrdersUseCaseTest extends TestCase
{
    private OrderClientInterface&MockInterface $orderClient;

    private OrderRepositoryInterface&MockInterface $orderRepository;

    private LoggerInterface&MockInterface $logger;

    private SyncOrdersUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderClient = Mockery::mock(OrderClientInterface::class);
        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new SyncOrdersUseCase(
            $this->orderClient,
            $this->orderRepository,
            $this->logger,
        );
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
            ->shouldReceive('iterateOrderBatches')
            ->once()
            ->with(null)
            ->andReturn($this->emptyGenerator());

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Starting full order sync from ShopWired');

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('Order sync completed: no orders found in ShopWired');

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
        $orders = [$this->createOrder(1), $this->createOrder(2)];

        $this->orderClient
            ->shouldReceive('iterateOrderBatches')
            ->once()
            ->with(1)
            ->andReturn($this->singlePageGenerator($orders));

        $this->orderRepository
            ->shouldReceive('saveMany')
            ->once()
            ->with($orders)
            ->andReturn(SaveManyResult::success(2));

        $this->logger->shouldReceive('info')->with(Mockery::pattern('/Starting.*order sync/'));
        $this->logger->shouldReceive('debug')->with('Fetched order page from API', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->with('Flushing order batch to database', Mockery::type('array'));
        $this->logger->shouldReceive('info')->with('Order sync completed', Mockery::type('array'));

        $result = $this->useCase->execute(1);

        $this->assertSame(2, $result->fetched);
        $this->assertSame(2, $result->saved);
        $this->assertSame(0, $result->failed);
        $this->assertTrue($result->allSaved());
    }

    /*
    |--------------------------------------------------------------------------
    | Buffer Flush Branch (10+ Pages)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_flushes_buffer_after_10_pages(): void
    {
        // Create 10 pages with 2 orders each = 20 orders total
        $ordersPerPage = [];
        for ($i = 0; $i < 10; $i++) {
            $ordersPerPage[$i] = [$this->createOrder($i * 2 + 1), $this->createOrder($i * 2 + 2)];
        }

        $this->orderClient
            ->shouldReceive('iterateOrderBatches')
            ->once()
            ->with(10)
            ->andReturn($this->multiPageGenerator($ordersPerPage));

        // Should flush once after 10 pages (20 orders)
        $this->orderRepository
            ->shouldReceive('saveMany')
            ->once()
            ->with(Mockery::on(static fn(array $orders) => \count($orders) === 20))
            ->andReturn(SaveManyResult::success(20));

        $this->logger->shouldReceive('info')->with(Mockery::pattern('/Starting.*order sync/'));
        $this->logger->shouldReceive('debug')->times(10)->with('Fetched order page from API', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->once()->with('Flushing order batch to database', Mockery::type('array'));
        $this->logger->shouldReceive('info')->with('Order sync completed', Mockery::type('array'));

        $result = $this->useCase->execute(10);

        $this->assertSame(20, $result->fetched);
        $this->assertSame(20, $result->saved);
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
        $orders = [$this->createOrder(1), $this->createOrder(2), $this->createOrder(3)];
        $failedRefs = [2, 3];

        $this->orderClient
            ->shouldReceive('iterateOrderBatches')
            ->once()
            ->with(1)
            ->andReturn($this->singlePageGenerator($orders));

        $this->orderRepository
            ->shouldReceive('saveMany')
            ->once()
            ->andReturn(new SaveManyResult(succeeded: 1, failed: 2, failedReferences: $failedRefs));

        $this->logger->shouldReceive('info')->with(Mockery::pattern('/Starting.*order sync/'));
        $this->logger->shouldReceive('debug')->with('Fetched order page from API', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->with('Flushing order batch to database', Mockery::type('array'));
        $this->logger
            ->shouldReceive('error')
            ->once()
            ->with('Failed to save some orders to database', Mockery::on(static fn(array $context) => $context['failed_count'] === 2
                    && $context['failed_ids'] === $failedRefs));
        $this->logger->shouldReceive('info')->with('Order sync completed', Mockery::type('array'));

        $result = $this->useCase->execute(1);

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
        // Create 12 pages: first 10 pages flush at batch boundary, last 2 flush at end
        $ordersPerPage = [];
        for ($i = 0; $i < 12; $i++) {
            $ordersPerPage[$i] = [$this->createOrder($i + 1)];
        }

        $this->orderClient
            ->shouldReceive('iterateOrderBatches')
            ->once()
            ->with(null)
            ->andReturn($this->multiPageGenerator($ordersPerPage));

        // First flush after 10 pages (10 orders)
        $this->orderRepository
            ->shouldReceive('saveMany')
            ->once()
            ->with(Mockery::on(static fn(array $orders) => \count($orders) === 10))
            ->andReturn(SaveManyResult::success(10));

        // Final flush with remaining 2 orders
        $this->orderRepository
            ->shouldReceive('saveMany')
            ->once()
            ->with(Mockery::on(static fn(array $orders) => \count($orders) === 2))
            ->andReturn(SaveManyResult::success(2));

        $this->logger->shouldReceive('info')->with(Mockery::pattern('/Starting.*order sync/'));
        $this->logger->shouldReceive('debug')->times(12)->with('Fetched order page from API', Mockery::type('array'));
        $this->logger->shouldReceive('debug')->times(2)->with('Flushing order batch to database', Mockery::type('array'));
        $this->logger->shouldReceive('info')->with('Order sync completed', Mockery::type('array'));

        $result = $this->useCase->execute();

        $this->assertSame(12, $result->fetched);
        $this->assertSame(12, $result->saved);
        $this->assertSame(0, $result->failed);
    }

    /*
    |--------------------------------------------------------------------------
    | maxPages Parameter
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_passes_max_pages_to_client(): void
    {
        $this->orderClient
            ->shouldReceive('iterateOrderBatches')
            ->once()
            ->with(5)
            ->andReturn($this->emptyGenerator());

        $this->logger->shouldReceive('info')->with('Starting limited (5 pages) order sync from ShopWired');
        $this->logger->shouldReceive('info')->with('Order sync completed: no orders found in ShopWired');

        $result = $this->useCase->execute(5);

        $this->assertTrue($result->isEmpty());
    }

    /*
    |--------------------------------------------------------------------------
    | Test Fixtures & Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @return Generator<int, list<Order>, mixed, void>
     */
    private function emptyGenerator(): Generator
    {
        yield from [];
    }

    /**
     * @param list<Order> $orders
     *
     * @return Generator<int, list<Order>, mixed, void>
     */
    private function singlePageGenerator(array $orders): Generator
    {
        yield 1 => $orders;
    }

    /**
     * @param array<int, list<Order>> $ordersPerPage Page number => orders
     *
     * @return Generator<int, list<Order>, mixed, void>
     */
    private function multiPageGenerator(array $ordersPerPage): Generator
    {
        foreach ($ordersPerPage as $pageNumber => $orders) {
            yield $pageNumber + 1 => $orders;
        }
    }

    private function createOrder(int $id): Order
    {
        return new Order(
            id: $id,
            reference: 10000 + $id,
            orderPlacedAt: new DateTimeImmutable(),
            total: 100.0,
            subTotalNet: 90.0,
            shippingTotalNet: 10.0,
            originalShippingTotalNet: 10.0,
            paymentMethod: PaymentMethod::Card,
            comments: '',
            marketing: false,
            hasVatRelief: false,
            isArchived: false,
            isAnonymized: false,
            lineItemVatCalculation: false,
            status: new OrderStatus(1, OrderStatusType::Completed, 'paid', 0),
            customer: new OrderCustomer(1, 1, null, []),
            shipping: null,
            billingAddress: $this->createAddress(),
            shippingAddress: $this->createAddress(),
            preOrderStatus: PreOrderStatus::None,
        );
    }

    private function createAddress(): OrderAddress
    {
        return new OrderAddress(
            name: 'Test',
            emailAddress: 'test@example.com',
            telephone: '01234567890',
            companyName: '',
            addressLine1: '123 Test St',
            addressLine2: '',
            addressLine3: null,
            city: 'London',
            province: '',
            state: null,
            postcode: 'SW1A 1AA',
            country: 'UK',
            countryId: 1,
        );
    }
}
