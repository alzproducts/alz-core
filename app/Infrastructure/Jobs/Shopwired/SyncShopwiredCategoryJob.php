<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Fetch the current state of a ShopWired category from the API and persist it.
 *
 * Dispatched by webhook use cases after a partial update. Ensures the local
 * record reflects the authoritative API state even if the webhook payload
 * was partial or arrived out of order.
 */
final class SyncShopwiredCategoryJob extends AbstractSyncShopwiredEntityJob
{
    public function handle(
        CategoryClientInterface $client,
        CategoryRepositoryInterface $repo,
        LoggerInterface $logger,
    ): void {
        $category = $client->getCategoryById($this->entityId->value);
        $repo->save($category);
        $logger->info('Category sync complete', ['category_id' => $this->entityId->value]);
    }

    protected function uniqueIdPrefix(): string
    {
        return 'sync-shopwired-category-';
    }

}
