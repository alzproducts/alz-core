<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Contracts\Shopwired\ProductClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Fetch the current state of a ShopWired product from the API and persist it.
 *
 * Dispatched by webhook use cases after a partial update. Ensures the local
 * record reflects the authoritative API state even if the webhook payload
 * was partial or arrived out of order.
 */
final class SyncShopwiredProductJob extends AbstractSyncShopwiredEntityJob
{
    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function handle(
        ProductClientInterface $client,
        ProductRepositoryInterface $repo,
        LoggerInterface $logger,
    ): void {
        $product = $client->getProductById($this->entityId->value);
        $repo->save($product);
        $logger->info('Product sync complete', ['product_id' => $this->entityId->value]);
    }

    protected function uniqueIdPrefix(): string
    {
        return 'sync-shopwired-product-';
    }

}
