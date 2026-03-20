<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Contracts\Shopwired\BrandClientInterface;
use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetch the current state of a ShopWired brand from the API and persist it.
 *
 * Dispatched by webhook use cases after a partial update. Ensures the local
 * record reflects the authoritative API state even if the webhook payload
 * was partial or arrived out of order.
 */
final class SyncShopwiredBrandJob extends AbstractSyncShopwiredEntityJob
{
    /**
     * @throws TransientApiFailure
     * @throws PermanentApiFailure
     * @throws Throwable
     */
    public function handle(
        BrandClientInterface $client,
        BrandRepositoryInterface $repo,
        LoggerInterface $logger,
    ): void {
        $this->withErrorHandling($logger, function () use ($client, $repo): void {
            $brand = $client->getBrandById($this->entityId->value);
            $repo->save($brand);
        });
    }

    protected function uniqueIdPrefix(): string
    {
        return 'sync-shopwired-brand-';
    }

    protected function contextKey(): string
    {
        return 'brand_id';
    }

    protected function entityLabel(): string
    {
        return 'Brand';
    }
}
