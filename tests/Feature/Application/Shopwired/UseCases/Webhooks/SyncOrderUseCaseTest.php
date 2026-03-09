<?php

declare(strict_types=1);

namespace Tests\Feature\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Jobs\Shopwired\SyncShopwiredOrderJob;
use App\Application\Shopwired\UseCases\Webhooks\SyncOrderUseCase;
use App\Domain\Catalog\Order\Enums\PreOrderStatus;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderAddress;
use App\Domain\Catalog\Order\ValueObjects\OrderCustomer;
use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Domain\Catalog\Order\ValueObjects\PaymentMethod;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * SyncOrderUseCase Unit Tests.
 *
 * Tests the staleness and idempotency guards that prevent stale or
 * duplicate webhook payloads from triggering unnecessary DB writes and jobs.
 */
#[CoversClass(SyncOrderUseCase::class)]
final class SyncOrderUseCaseTest extends TestCase
{
    private const int STALENESS_HOURS = 24;

    private OrderRepositoryInterface&MockInterface $repository;

    private LoggerInterface&MockInterface $logger;

    private SyncOrderUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->repository = Mockery::mock(OrderRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new SyncOrderUseCase(
            orderRepository: $this->repository,
            logger: $this->logger,
            webhookStalenessHours: self::STALENESS_HOURS,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Staleness Guard
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_discards_events_older_than_the_staleness_window(): void
    {
        $order = $this->createOrder(id: 101);
        $staleEventTime = new DateTimeImmutable(\sprintf('-%d hours', self::STALENESS_HOURS + 1));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Discarding stale order webhook', Mockery::type('array'));

        $this->repository->shouldNotReceive('saveFromWebhook');

        $this->useCase->execute(eventTime: $staleEventTime, webhookId: 99, order: $order);

    }

    /*
    |--------------------------------------------------------------------------
    | Idempotency Guard
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_discards_events_when_a_newer_webhook_was_already_processed(): void
    {
        $order = $this->createOrder(id: 101);
        $eventTime = new DateTimeImmutable('-1 hour');
        $newerTimestamp = new DateTimeImmutable('now');

        $this->repository->shouldReceive('getWebhookTimestamp')->once()->andReturn($newerTimestamp);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Discarding already-processed order webhook', Mockery::type('array'));

        $this->repository->shouldNotReceive('saveFromWebhook');

        $this->useCase->execute(eventTime: $eventTime, webhookId: 99, order: $order);

    }

    #[Test]
    public function it_discards_events_when_the_exact_same_webhook_timestamp_was_already_processed(): void
    {
        $order = $this->createOrder(id: 101);
        $eventTime = new DateTimeImmutable('-1 hour');

        $this->repository->shouldReceive('getWebhookTimestamp')->once()->andReturn($eventTime);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Discarding already-processed order webhook', Mockery::type('array'));

        $this->repository->shouldNotReceive('saveFromWebhook');

        $this->useCase->execute(eventTime: $eventTime, webhookId: 99, order: $order);

    }

    /*
    |--------------------------------------------------------------------------
    | Happy Path
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_saves_and_dispatches_sync_job_for_a_fresh_webhook(): void
    {
        Queue::fake();

        $order = $this->createOrder(id: 101);
        $eventTime = new DateTimeImmutable('-1 hour');

        $this->repository->shouldReceive('getWebhookTimestamp')->once()->andReturn(null);
        $this->repository->shouldReceive('saveFromWebhook')->once()->with($order, $eventTime);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Order webhook processed — sync queued', Mockery::type('array'));

        $this->useCase->execute(eventTime: $eventTime, webhookId: 99, order: $order);

        Queue::assertPushed(SyncShopwiredOrderJob::class);
    }

    #[Test]
    public function it_saves_and_dispatches_sync_job_when_incoming_event_is_newer_than_stored(): void
    {
        Queue::fake();

        $order = $this->createOrder(id: 101);
        $eventTime = new DateTimeImmutable('-1 hour');
        $olderTimestamp = new DateTimeImmutable('-2 hours');

        $this->repository->shouldReceive('getWebhookTimestamp')->once()->andReturn($olderTimestamp);
        $this->repository->shouldReceive('saveFromWebhook')->once()->with($order, $eventTime);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Order webhook processed — sync queued', Mockery::type('array'));

        $this->useCase->execute(eventTime: $eventTime, webhookId: 99, order: $order);

        Queue::assertPushed(SyncShopwiredOrderJob::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
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
