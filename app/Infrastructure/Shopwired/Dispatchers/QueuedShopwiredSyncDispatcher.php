<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Dispatchers;

use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Domain\Catalog\Product\Commands\SetFreeDeliveryCommand;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Jobs\Shopwired\SetProductFreeDeliveryJob;
use App\Infrastructure\Jobs\Shopwired\SyncShopwiredBrandJob;
use App\Infrastructure\Jobs\Shopwired\SyncShopwiredCategoryJob;
use App\Infrastructure\Jobs\Shopwired\SyncShopwiredCustomerJob;
use App\Infrastructure\Jobs\Shopwired\SyncShopwiredOrderJob;
use App\Infrastructure\Jobs\Shopwired\SyncShopwiredOrdersRangeJob;
use App\Infrastructure\Jobs\Shopwired\SyncShopwiredProductJob;
use DateTimeImmutable;
use Override;

/**
 * Queue-backed dispatcher for ShopWired entity synchronisation.
 *
 * Translates Application-layer dispatch intents into concrete Laravel job dispatches.
 */
final readonly class QueuedShopwiredSyncDispatcher implements ShopwiredSyncDispatcherInterface
{
    #[Override]
    public function dispatchOrderSync(IntId $entityId): void
    {
        SyncShopwiredOrderJob::dispatch($entityId);
    }

    #[Override]
    public function dispatchProductSync(IntId $entityId): void
    {
        SyncShopwiredProductJob::dispatch($entityId);
    }

    #[Override]
    public function dispatchCustomerSync(IntId $entityId): void
    {
        SyncShopwiredCustomerJob::dispatch($entityId);
    }

    #[Override]
    public function dispatchBrandSync(IntId $entityId): void
    {
        SyncShopwiredBrandJob::dispatch($entityId);
    }

    #[Override]
    public function dispatchCategorySync(IntId $entityId): void
    {
        SyncShopwiredCategoryJob::dispatch($entityId);
    }

    #[Override]
    public function dispatchOrdersRangeSync(DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        SyncShopwiredOrdersRangeJob::dispatch($from, $to);
    }

    #[Override]
    public function dispatchFreeDeliveryUpdate(SetFreeDeliveryCommand $command): void
    {
        SetProductFreeDeliveryJob::dispatch($command);
    }
}
