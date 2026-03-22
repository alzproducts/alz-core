<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Contracts\Shopwired\CustomerClientInterface;
use App\Application\Contracts\Shopwired\CustomerRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Fetch the current state of a ShopWired customer from the API and persist it.
 *
 * Dispatched by webhook use cases after a partial update. Ensures the local
 * record reflects the authoritative API state even if the webhook payload
 * was partial or arrived out of order.
 */
final class SyncShopwiredCustomerJob extends AbstractSyncShopwiredEntityJob
{
    public function handle(
        CustomerClientInterface $client,
        CustomerRepositoryInterface $repo,
        LoggerInterface $logger,
    ): void {
        $customer = $client->getCustomerById($this->entityId->value);
        $repo->save($customer);
        $logger->info('Customer sync complete', ['customer_id' => $this->entityId->value]);
    }

    protected function uniqueIdPrefix(): string
    {
        return 'sync-shopwired-customer-';
    }

}
