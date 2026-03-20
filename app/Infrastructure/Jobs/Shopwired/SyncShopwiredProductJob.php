<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Contracts\Shopwired\ProductClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use Psr\Log\LoggerInterface;
use Throwable;

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
     * @throws TransientApiFailure
     * @throws PermanentApiFailure
     * @throws Throwable
     */
    public function handle(
        ProductClientInterface $client,
        ProductRepositoryInterface $repo,
        LoggerInterface $logger,
    ): void {
        $this->withErrorHandling($logger, function () use ($client, $repo): void {
            $product = $client->getProductById($this->entityId->value);
            $repo->save($product);
        });
    }

    protected function uniqueIdPrefix(): string
    {
        return 'sync-shopwired-product-';
    }

    protected function contextKey(): string
    {
        return 'product_id';
    }

    protected function entityLabel(): string
    {
        return 'Product';
    }
}
