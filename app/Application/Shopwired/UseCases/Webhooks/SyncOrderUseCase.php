<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Jobs\Shopwired\SyncShopwiredOrderJob;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Handle `order.updated` and `order.finalized` webhook events.
 *
 * Applies staleness and idempotency guards, persists the order from the webhook
 * payload with its timestamp, then queues a full API sync.
 */
final readonly class SyncOrderUseCase
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private LoggerInterface $logger,
        private int $webhookStalenessHours,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(DateTimeImmutable $eventTime, int $webhookId, Order $order): void
    {
        $context = ['webhook_id' => $webhookId, 'subject_id' => $order->id];
        $this->logger->info('Processing order webhook', $context);

        $cutoff = (new DateTimeImmutable())->setTimestamp(\time() - ($this->webhookStalenessHours * 3600));

        if ($eventTime < $cutoff) {
            $this->logger->info('Discarding stale order webhook', $context);

            return;
        }

        $orderId = IntId::from($order->id);
        $existing = $this->orderRepository->getWebhookTimestamp($orderId);

        if ($existing !== null && $existing >= $eventTime) {
            $this->logger->info('Discarding already-processed order webhook', $context);

            return;
        }

        $this->orderRepository->saveFromWebhook($order, $eventTime);

        SyncShopwiredOrderJob::dispatch($orderId);

        $this->logger->info('Order webhook processed — sync queued', $context);
    }
}
