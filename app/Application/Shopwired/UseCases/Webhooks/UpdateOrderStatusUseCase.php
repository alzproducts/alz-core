<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Application\Shopwired\DTOs\WebhookContextDTO;
use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Handle `order.status_changed` webhook events.
 *
 * Applies staleness and idempotency guards, updates order status fields,
 * records the webhook event, then queues a full API sync.
 */
final readonly class UpdateOrderStatusUseCase
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private ShopwiredSyncDispatcherInterface $dispatcher,
        private WebhookIdempotencyServiceInterface $idempotency,
        private LoggerInterface $logger,
        private int $webhookStalenessHours,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws RecordNotFoundException When order row not found in database
     */
    public function execute(WebhookContextDTO $context, IntId $orderId, OrderStatus $status): void
    {
        $logContext = ['webhook_id' => $context->webhookId, 'subject_id' => $orderId->value];
        $this->logger->info('Processing order status webhook', $logContext);

        $cutoff = (new DateTimeImmutable())->setTimestamp(\time() - ($this->webhookStalenessHours * 3600));

        if ($context->eventTime < $cutoff) {
            $this->logger->info('Discarding stale order status webhook', $logContext);

            return;
        }

        if ($this->idempotency->isSuperseded($orderId, $context->topic, $context->webhookId)) {
            $this->logger->info('Discarding superseded order status webhook', $logContext);

            return;
        }

        $this->orderRepository->updateStatus($orderId, $status);
        $this->idempotency->record($orderId, $context->topic, $context->webhookId, $context->eventTime);

        $this->dispatcher->dispatchOrderSync($orderId);

        $this->logger->info('Order status webhook processed — sync queued', $logContext);
    }
}
