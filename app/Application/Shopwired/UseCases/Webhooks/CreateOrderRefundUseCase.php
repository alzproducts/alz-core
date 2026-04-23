<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Domain\Catalog\Order\ValueObjects\OrderRefund;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Handle `order.refund.created` webhook events.
 *
 * Applies staleness guard, inserts refund (idempotent via unique constraint
 * on refund external ID), then queues a full API sync.
 */
final readonly class CreateOrderRefundUseCase
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private ShopwiredSyncDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
        private int $webhookStalenessHours,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     * @throws RecordNotFoundException When order row not found in database
     */
    public function execute(DateTimeImmutable $eventTime, int $webhookId, IntId $orderId, OrderRefund $refund): void
    {
        $context = ['webhook_id' => $webhookId, 'order_id' => $orderId->value, 'refund_id' => $refund->externalId];
        $this->logger->info('Processing order refund webhook', $context);

        $cutoff = (new DateTimeImmutable())->setTimestamp(\time() - ($this->webhookStalenessHours * 3600));

        if ($eventTime < $cutoff) {
            $this->logger->info('Discarding stale order refund webhook', $context);

            return;
        }

        try {
            $this->orderRepository->addRefund($orderId, $refund);
        } catch (DuplicateRecordException) {
            $this->logger->info('Discarding duplicate order refund webhook', $context);

            return;
        }

        $this->dispatcher->dispatchOrderSync($orderId);

        $this->logger->info('Order refund webhook processed — sync queued', $context);
    }
}
