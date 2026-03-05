<?php

declare(strict_types=1);

namespace App\Application\Jobs\Shopwired;

use App\Application\Contracts\Shopwired\CustomerClientInterface;
use App\Application\Contracts\Shopwired\CustomerRepositoryInterface;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetch the current state of a ShopWired customer from the API and persist it.
 *
 * Dispatched by webhook use cases after a partial update. Ensures the local
 * record reflects the authoritative API state even if the webhook payload
 * was partial or arrived out of order.
 */
final class ReconcileShopwiredCustomerJob extends AbstractReconcileShopwiredEntityJob
{
    /**
     * @throws TransientApiFailure
     * @throws PermanentApiFailure
     * @throws Throwable
     */
    public function handle(
        CustomerClientInterface $client,
        CustomerRepositoryInterface $repo,
        LoggerInterface $logger,
    ): void {
        $customer = $client->getCustomerById($this->entityId->value);
        $this->executeSync($customer, $repo, $logger);
    }

    protected function uniqueIdPrefix(): string
    {
        return 'reconcile-shopwired-customer-';
    }

    protected function contextKey(): string
    {
        return 'customer_id';
    }

    protected function entityLabel(): string
    {
        return 'Customer';
    }
}
