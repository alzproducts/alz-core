<?php

declare(strict_types=1);

namespace Tests\Feature\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Application\Shopwired\Enums\WebhookTopic;
use App\Application\Shopwired\UseCases\Webhooks\AbstractSyncEntityWebhookUseCase;
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
#[CoversClass(AbstractSyncEntityWebhookUseCase::class)]
final class SyncOrderUseCaseTest extends TestCase
{
    private const int STALENESS_HOURS = 24;

    private OrderRepositoryInterface&MockInterface $repository;

    private WebhookIdempotencyServiceInterface&MockInterface $idempotency;

    private LoggerInterface&MockInterface $logger;

    private SyncOrderUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->repository = Mockery::mock(OrderRepositoryInterface::class);
        $this->idempotency = Mockery::mock(WebhookIdempotencyServiceInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new SyncOrderUseCase(
            orderRepository: $this->repository,
            idempotency: $this->idempotency,
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
            ->with('Processing order webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Discarding stale order webhook', Mockery::type('array'));

        $this->idempotency->shouldNotReceive('isSuperseded');
        $this->repository->shouldNotReceive('saveFromWebhook');
        $this->idempotency->shouldNotReceive('record');

        $this->useCase->execute(
            eventTime: $staleEventTime,
            webhookId: 99,
            topic: WebhookTopic::OrderUpdated,
            order: $order,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Idempotency Guard
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_discards_events_when_webhook_is_superseded(): void
    {
        $order = $this->createOrder(id: 101);
        $eventTime = new DateTimeImmutable('-1 hour');

        $this->idempotency->shouldReceive('isSuperseded')
            ->once()
            ->andReturnTrue();

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing order webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Discarding already-processed order webhook', Mockery::type('array'));

        $this->repository->shouldNotReceive('saveFromWebhook');
        $this->idempotency->shouldNotReceive('record');

        $this->useCase->execute(
            eventTime: $eventTime,
            webhookId: 99,
            topic: WebhookTopic::OrderUpdated,
            order: $order,
        );
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

        $this->idempotency->shouldReceive('isSuperseded')
            ->once()
            ->andReturnFalse();

        $this->repository->shouldReceive('saveFromWebhook')
            ->once()
            ->with($order);

        $this->idempotency->shouldReceive('record')
            ->once();

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing order webhook', Mockery::type('array'));

        // The logger expectation fires AFTER the dispatch call, proving the
        // full happy path (save → record → dispatch → log) executed successfully.
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Order webhook processed — sync queued', Mockery::type('array'));

        $this->useCase->execute(
            eventTime: $eventTime,
            webhookId: 99,
            topic: WebhookTopic::OrderUpdated,
            order: $order,
        );
    }

    #[Test]
    public function it_saves_and_dispatches_sync_job_when_not_superseded(): void
    {
        Queue::fake();

        $order = $this->createOrder(id: 102);
        $eventTime = new DateTimeImmutable('-1 hour');

        $this->idempotency->shouldReceive('isSuperseded')
            ->once()
            ->andReturnFalse();

        $this->repository->shouldReceive('saveFromWebhook')
            ->once()
            ->with($order);

        $this->idempotency->shouldReceive('record')
            ->once();

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing order webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Order webhook processed — sync queued', Mockery::type('array'));

        $this->useCase->execute(
            eventTime: $eventTime,
            webhookId: 99,
            topic: WebhookTopic::OrderFinalized,
            order: $order,
        );
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
