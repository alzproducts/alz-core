<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Shopwired\UseCases\Webhooks\DeleteOrderUseCase;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Notifications\Events\AdminAlertEvent;
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
 * DeleteOrderUseCase Unit Tests.
 *
 * Tests the idempotency of order deletion and the admin alert on success.
 */
#[CoversClass(DeleteOrderUseCase::class)]
final class DeleteOrderUseCaseTest extends TestCase
{
    private OrderRepositoryInterface&MockInterface $repository;

    private LoggerInterface&MockInterface $logger;

    private DeleteOrderUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([AdminAlertEvent::class]);

        $this->repository = Mockery::mock(OrderRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new DeleteOrderUseCase(
            orderRepository: $this->repository,
            logger: $this->logger,
            eventDispatcher: \app(Dispatcher::class),
        );
    }

    #[Test]
    public function it_deletes_the_order_and_fires_admin_alert(): void
    {
        $orderId = IntId::from(42);

        $this->repository->shouldReceive('deleteByExternalId')->once()->with($orderId);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Order deleted', Mockery::type('array'));

        $this->useCase->execute(webhookId: 1, orderId: $orderId);

        Event::assertDispatched(
            AdminAlertEvent::class,
            static fn(AdminAlertEvent $e): bool => $e->title === 'ShopWired Order Deleted'
                && \str_contains($e->message, '42'),
        );
    }

    #[Test]
    public function it_is_idempotent_when_order_is_already_deleted(): void
    {
        $orderId = IntId::from(42);

        $this->repository->shouldReceive('deleteByExternalId')
            ->once()
            ->andThrow(new ResourceNotFoundException('ShopWired', 'order', 42));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Order already deleted — skipping', Mockery::type('array'));

        $this->useCase->execute(webhookId: 1, orderId: $orderId);

        Event::assertNotDispatched(AdminAlertEvent::class);
    }
}
