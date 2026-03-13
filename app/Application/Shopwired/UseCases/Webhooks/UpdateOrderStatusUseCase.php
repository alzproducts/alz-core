<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Application\Jobs\Shopwired\SyncShopwiredOrderJob;
use App\Application\Shopwired\Enums\WebhookTopic;
use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
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
        private WebhookIdempotencyServiceInterface $idempotency,
        private LoggerInterface $logger,
        private int $webhookStalenessHours,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(DateTimeImmutable $eventTime, int $webhookId, WebhookTopic $topic, IntId $orderId, OrderStatus $status): void
    {
        $context = ['webhook_id' => $webhookId, 'subject_id' => $orderId->value];
        $this->logger->info('Processing order status webhook', $context);

        $cutoff = (new DateTimeImmutable())->setTimestamp(\time() - ($this->webhookStalenessHours * 3600));

        if ($eventTime < $cutoff) {
            $this->logger->info('Discarding stale order status webhook', $context);

            return;
        }

        if ($this->idempotency->isSuperseded($orderId, $topic, $webhookId)) {
            $this->logger->info('Discarding superseded order status webhook', $context);

            return;
        }

        $this->orderRepository->updateStatus($orderId, $status);
        $this->idempotency->record($orderId, $topic, $webhookId, $eventTime);

        SyncShopwiredOrderJob::dispatch($orderId);

        $this->logger->info('Order status webhook processed — sync queued', $context);
    }
}
