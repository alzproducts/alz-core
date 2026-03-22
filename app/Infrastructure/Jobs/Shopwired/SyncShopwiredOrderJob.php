<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Contracts\Shopwired\OrderClientInterface;
use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Fetch the current state of a ShopWired order from the API and persist it.
 *
 * Dispatched by webhook use cases after a partial update. Ensures the local
 * record reflects the authoritative API state even if the webhook payload
 * was partial or arrived out of order.
 */
final class SyncShopwiredOrderJob extends AbstractSyncShopwiredEntityJob
{
    public function handle(
        OrderClientInterface $client,
        OrderRepositoryInterface $repo,
        LoggerInterface $logger,
    ): void {
        $order = $client->getOrderById($this->entityId->value);
        $repo->save($order);
        $logger->info('Order sync complete', ['order_id' => $this->entityId->value]);
    }

    protected function uniqueIdPrefix(): string
    {
        return 'sync-shopwired-order-';
    }

}
