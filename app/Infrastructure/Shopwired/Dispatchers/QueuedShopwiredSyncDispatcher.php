<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Dispatchers;

use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Application\Jobs\Shopwired\SetProductFreeDeliveryJob;
use App\Application\Jobs\Shopwired\SyncShopwiredBrandJob;
use App\Application\Jobs\Shopwired\SyncShopwiredCategoryJob;
use App\Application\Jobs\Shopwired\SyncShopwiredCustomerJob;
use App\Application\Jobs\Shopwired\SyncShopwiredOrderJob;
use App\Application\Jobs\Shopwired\SyncShopwiredOrdersRangeJob;
use App\Application\Jobs\Shopwired\SyncShopwiredProductJob;
use App\Domain\Catalog\Product\Commands\SetFreeDeliveryCommand;
use App\Domain\ValueObjects\IntId;
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

    /**
     * @param list<SetFreeDeliveryCommand> $commands
     */
    #[Override]
    public function dispatchFreeDeliveryUpdate(array $commands): void
    {
        SetProductFreeDeliveryJob::dispatch($commands);
    }
}
