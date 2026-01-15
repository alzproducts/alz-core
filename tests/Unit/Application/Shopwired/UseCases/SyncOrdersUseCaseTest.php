<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\OrderClientInterface;
use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Shopwired\UseCases\SyncOrdersUseCase;
use App\Application\Shopwired\ValueObjects\SaveManyResult;
use App\Domain\Catalog\Order\Enums\PreOrderStatus;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderAddress;
use App\Domain\Catalog\Order\ValueObjects\OrderCustomer;
use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Domain\Catalog\Order\ValueObjects\PaymentMethod;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * SyncOrdersUseCase Unit Tests.
 *
 * Tests the orchestration logic: empty orders handling, failure logging, result construction.
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
        $from = new DateTimeImmutable('2024-01-01');
        $to = new DateTimeImmutable('2024-01-02');

        $this->orderClient
            ->shouldReceive('listOrdersInRangeWithDetails')
            ->once()
            ->with($from, $to)
            ->andReturn([]);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('No orders found in date range', Mockery::type('array'));

        $result = $this->useCase->execute($from, $to);

        $this->assertTrue($result->isEmpty());
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->saved);
        $this->assertSame(0, $result->failed);
    }

    /*
    |--------------------------------------------------------------------------
    | Successful Save Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_returns_success_result_when_all_orders_saved(): void
    {
        $from = new DateTimeImmutable('2024-01-01');
        $to = new DateTimeImmutable('2024-01-02');
        $orders = [$this->createOrder(1), $this->createOrder(2)];

        $this->orderClient
            ->shouldReceive('listOrdersInRangeWithDetails')
            ->once()
            ->andReturn($orders);

        $this->orderRepository
            ->shouldReceive('saveMany')
            ->once()
            ->with($orders)
            ->andReturn(SaveManyResult::success(2));

        $this->logger->shouldNotReceive('warning');

        $result = $this->useCase->execute($from, $to);

        $this->assertSame(2, $result->fetched);
        $this->assertSame(2, $result->saved);
        $this->assertSame(0, $result->failed);
        $this->assertTrue($result->allSaved());
    }

    /*
    |--------------------------------------------------------------------------
    | Partial Failure Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_logs_warning_when_some_orders_fail_to_save(): void
    {
        $from = new DateTimeImmutable('2024-01-01');
        $to = new DateTimeImmutable('2024-01-02');
        $orders = [$this->createOrder(1), $this->createOrder(2), $this->createOrder(3)];
        $failedRefs = [2, 3];

        $this->orderClient
            ->shouldReceive('listOrdersInRangeWithDetails')
            ->once()
            ->andReturn($orders);

        $this->orderRepository
            ->shouldReceive('saveMany')
            ->once()
            ->andReturn(new SaveManyResult(succeeded: 1, failed: 2, failedReferences: $failedRefs));

        $this->logger
            ->shouldReceive('warning')
            ->once()
            ->with('Some orders failed to save', ['failed_references' => $failedRefs]);

        $result = $this->useCase->execute($from, $to);

        $this->assertSame(3, $result->fetched);
        $this->assertSame(1, $result->saved);
        $this->assertSame(2, $result->failed);
        $this->assertSame($failedRefs, $result->failedReferences);
        $this->assertTrue($result->hasFailures());
    }

    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

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
