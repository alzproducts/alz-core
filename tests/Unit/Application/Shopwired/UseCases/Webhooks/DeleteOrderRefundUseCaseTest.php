<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Application\Shopwired\UseCases\Webhooks\DeleteOrderRefundUseCase;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Notifications\Events\ManagerAlertEvent;
use App\Domain\ValueObjects\IntId;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * DeleteOrderRefundUseCase Unit Tests.
 *
 * Tests the idempotency of refund deletion, sync job queuing, and admin alert dispatch.
 */
#[CoversClass(DeleteOrderRefundUseCase::class)]
final class DeleteOrderRefundUseCaseTest extends TestCase
{
    private OrderRepositoryInterface&MockInterface $repository;

    private ShopwiredSyncDispatcherInterface&MockInterface $dispatcher;

    private LoggerInterface&MockInterface $logger;

    private DeleteOrderRefundUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ManagerAlertEvent::class]);

        $this->repository = Mockery::mock(OrderRepositoryInterface::class);
        $this->dispatcher = Mockery::mock(ShopwiredSyncDispatcherInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new DeleteOrderRefundUseCase(
            orderRepository: $this->repository,
            dispatcher: $this->dispatcher,
            logger: $this->logger,
            eventDispatcher: \app(Dispatcher::class),
        );
    }

    #[Test]
    public function it_deletes_the_refund_queues_sync_and_fires_admin_alert(): void
    {
        $orderId = IntId::from(100);
        $refundId = IntId::from(999);

        $this->repository->shouldReceive('deleteRefund')
            ->once()
            ->with($orderId, $refundId);

        $this->dispatcher->shouldReceive('dispatchOrderSync')
            ->once()
            ->with($orderId);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing order refund delete webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Order refund deleted — sync queued', Mockery::type('array'));

        $this->useCase->execute(webhookId: 1, orderId: $orderId, refundExternalId: $refundId);

        Event::assertDispatched(
            ManagerAlertEvent::class,
            static fn(ManagerAlertEvent $e): bool => $e->title === 'ShopWired Order Refund Deleted'
                && \str_contains($e->message, '999')
                && \str_contains($e->message, '100'),
        );
    }

    #[Test]
    public function it_is_idempotent_when_refund_is_already_deleted(): void
    {
        $orderId = IntId::from(100);
        $refundId = IntId::from(999);

        $this->repository->shouldReceive('deleteRefund')
            ->once()
            ->andThrow(new RecordNotFoundException('order_refund', 999));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Processing order refund delete webhook', Mockery::type('array'));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Order refund already deleted — skipping', Mockery::type('array'));

        $this->useCase->execute(webhookId: 1, orderId: $orderId, refundExternalId: $refundId);

        Event::assertNotDispatched(ManagerAlertEvent::class);
    }
}
