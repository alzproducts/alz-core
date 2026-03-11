<?php

declare(strict_types=1);

namespace App\Application\Jobs\Shopwired;

use App\Application\Contracts\Shopwired\OrderClientInterface;
use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetch the current state of a ShopWired order from the API and persist it.
 *
 * Dispatched by webhook use cases after a partial update. Ensures the local
 * record reflects the authoritative API state even if the webhook payload
 * was partial or arrived out of order.
 */
final class SyncShopwiredOrderJob extends AbstractSyncShopwiredEntityJob
{
    /**
     * @throws TransientApiFailure
     * @throws PermanentApiFailure
     * @throws Throwable
     */
    public function handle(
        OrderClientInterface $client,
        OrderRepositoryInterface $repo,
        LoggerInterface $logger,
    ): void {
        $order = $client->getOrderById($this->entityId->value);
        $this->executeSync($order, $repo, $logger);
    }

    protected function uniqueIdPrefix(): string
    {
        return 'sync-shopwired-order-';
    }

    protected function contextKey(): string
    {
        return 'order_id';
    }

    protected function entityLabel(): string
    {
        return 'Order';
    }
}
