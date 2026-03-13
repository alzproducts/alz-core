<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Notifications\Events\AdminAlertEvent;
use App\Domain\ValueObjects\IntId;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;

/**
 * Handle `order.deleted` webhook events.
 *
 * Hard-deletes the order by external ID. Cascades to child tables
 * (products, discounts, refunds, admin comments) via FK constraints.
 * Idempotent — logs and returns silently if the order no longer exists.
 * No staleness check — delete events are fired once and have no safety net.
 */
final readonly class DeleteOrderUseCase
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private LoggerInterface $logger,
        private Dispatcher $eventDispatcher,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(int $webhookId, IntId $orderId): void
    {
        $context = ['webhook_id' => $webhookId, 'subject_id' => $orderId->value];
        $this->logger->info('Processing order delete webhook', $context);

        try {
            $this->orderRepository->deleteByExternalId($orderId);
        } catch (ResourceNotFoundException) {
            $this->logger->info('Order already deleted — skipping', $context);

            return;
        }

        $this->logger->info('Order deleted', $context);

        $this->eventDispatcher->dispatch(new AdminAlertEvent(
            title: 'ShopWired Order Deleted',
            message: "Order #{$orderId->value} was deleted in ShopWired. Please investigate if this was unexpected.",
            context: $context,
        ));
    }
}
