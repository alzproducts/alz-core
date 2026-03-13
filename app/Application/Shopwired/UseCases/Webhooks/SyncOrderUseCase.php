<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Application\Jobs\Shopwired\SyncShopwiredOrderJob;
use App\Application\Shopwired\Enums\WebhookTopic;
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
 * payload, records the webhook event, then queues a full API sync.
 */
final readonly class SyncOrderUseCase
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private WebhookIdempotencyServiceInterface $idempotency,
        private LoggerInterface $logger,
        private int $webhookStalenessHours,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(DateTimeImmutable $eventTime, int $webhookId, WebhookTopic $topic, Order $order): void
    {
        $context = ['webhook_id' => $webhookId, 'subject_id' => $order->id];
        $this->logger->info('Processing order webhook', $context);

        $cutoff = (new DateTimeImmutable())->setTimestamp(\time() - ($this->webhookStalenessHours * 3600));

        if ($eventTime < $cutoff) {
            $this->logger->info('Discarding stale order webhook', $context);

            return;
        }

        $orderId = IntId::from($order->id);

        if ($this->idempotency->isSuperseded($orderId, $topic, $webhookId)) {
            $this->logger->info('Discarding already-processed order webhook', $context);

            return;
        }

        $this->orderRepository->saveFromWebhook($order);
        $this->idempotency->record($orderId, $topic, $webhookId, $eventTime);

        SyncShopwiredOrderJob::dispatch($orderId);

        $this->logger->info('Order webhook processed — sync queued', $context);
    }
}
