<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Notifications\Events\ManagerAlertEvent;
use App\Domain\ValueObjects\IntId;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;

/**
 * Handle `order.refund.deleted` webhook events.
 *
 * Deletes the refund by external ID. Idempotent — logs and returns
 * silently if the refund no longer exists. No staleness check — delete
 * events are one-time. Queues a full order sync for reconciliation
 * and dispatches a manager alert (refund deletion is unusual).
 */
final readonly class DeleteOrderRefundUseCase
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private ShopwiredSyncDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
        private Dispatcher $eventDispatcher,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(int $webhookId, IntId $orderId, IntId $refundExternalId): void
    {
        $context = ['webhook_id' => $webhookId, 'order_id' => $orderId->value, 'refund_id' => $refundExternalId->value];
        $this->logger->info('Processing order refund delete webhook', $context);

        try {
            $this->orderRepository->deleteRefund($orderId, $refundExternalId);
        } catch (ResourceNotFoundException) {
            $this->logger->info('Order refund already deleted — skipping', $context);

            return;
        }

        $this->dispatcher->dispatchOrderSync($orderId);

        $this->logger->info('Order refund deleted — sync queued', $context);

        $this->eventDispatcher->dispatch(new ManagerAlertEvent(
            title: 'ShopWired Order Refund Deleted',
            message: "Refund #{$refundExternalId->value} on order #{$orderId->value} was deleted in ShopWired. Please investigate if this was unexpected.",
            context: $context,
        ));
    }
}
