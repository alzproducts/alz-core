<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Contracts\Shopwired\BrandClientInterface;
use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

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
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function handle(
        BrandClientInterface $client,
        BrandRepositoryInterface $repo,
        LoggerInterface $logger,
    ): void {
        $brand = $client->getBrandById($this->entityId->value);
        $repo->save($brand);
        $logger->info('Brand sync complete', ['brand_id' => $this->entityId->value]);
    }

    protected function uniqueIdPrefix(): string
    {
        return 'sync-shopwired-brand-';
    }

}
