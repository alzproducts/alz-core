<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Application\Shopwired\Enums\WebhookTopic;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Handle `order.updated` and `order.finalized` webhook events.
 *
 * Applies staleness and idempotency guards, persists the order from the webhook
 * payload, records the webhook event, then queues a full API sync.
 */
final readonly class SyncOrderUseCase extends AbstractSyncEntityWebhookUseCase
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private ShopwiredSyncDispatcherInterface $dispatcher,
        WebhookIdempotencyServiceInterface $idempotency,
        LoggerInterface $logger,
        int $webhookStalenessHours,
    ) {
        parent::__construct($idempotency, $logger, $webhookStalenessHours);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(DateTimeImmutable $eventTime, int $webhookId, WebhookTopic $topic, Order $order): void
    {
        $this->process($eventTime, $webhookId, $topic, $order->id, $order);
    }

    /**
     * @param list<string> $presentEmbeds
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    protected function saveEntity(object $entity, array $presentEmbeds): void
    {
        /** @var Order $entity */
        $this->orderRepository->saveFromWebhook($entity);
    }

    #[Override]
    protected function dispatchSyncJob(IntId $entityId): void
    {
        $this->dispatcher->dispatchOrderSync($entityId);
    }

    #[Override]
    protected function entityLabel(): string
    {
        return 'Order';
    }
}
