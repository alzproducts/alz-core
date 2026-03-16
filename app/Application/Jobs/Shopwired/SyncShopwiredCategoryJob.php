<?php

declare(strict_types=1);

namespace App\Application\Jobs\Shopwired;

use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetch the current state of a ShopWired category from the API and persist it.
 *
 * Dispatched by webhook use cases after a partial update. Ensures the local
 * record reflects the authoritative API state even if the webhook payload
 * was partial or arrived out of order.
 */
final class SyncShopwiredCategoryJob extends AbstractSyncShopwiredEntityJob
{
    /**
     * @throws TransientApiFailure
     * @throws PermanentApiFailure
     * @throws Throwable
     */
    public function handle(
        CategoryClientInterface $client,
        CategoryRepositoryInterface $repo,
        LoggerInterface $logger,
    ): void {
        $this->withErrorHandling($logger, function () use ($client, $repo): void {
            $category = $client->getCategoryById($this->entityId->value);
            $repo->save($category);
        });
    }

    protected function uniqueIdPrefix(): string
    {
        return 'sync-shopwired-category-';
    }

    protected function contextKey(): string
    {
        return 'category_id';
    }

    protected function entityLabel(): string
    {
        return 'Category';
    }
}
