<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Application\Jobs\Inventory\SyncDeltaStockToShopwiredJob;
use App\Application\Jobs\Inventory\SyncFullStockToShopwiredJob;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Inventory Schedule Definitions
 *
 * Registers the two-tier Linnworks → ShopWired stock sync strategy:
 * - Delta sync (every 5 min): incremental, cursor-based, fast path
 * - Full sync (every 15 min): safety net for drift delta may miss
 *
 * Mutual exclusion between the two syncs is handled at the use-case level
 * via a shared blocking cache lock — not at the schedule level.
 * withoutOverlapping() here only prevents duplicate self-dispatch.
 */
final class InventoryScheduleServiceProvider extends ServiceProvider
{
    /**
     * @throws RuntimeException
     */
    public function boot(): void
    {
        // EVERY 5 MIN: Incremental stock sync from Linnworks → ShopWired
        // Queries only SKUs changed since the last cursor for near-real-time accuracy.
        Schedule::job(new SyncDeltaStockToShopwiredJob())
            ->name('sync-delta-stock-to-shopwired')
            ->everyFiveMinutes()
            ->onOneServer()
            ->withoutOverlapping(5);

        // EVERY 15 MIN: Full stock sync from Linnworks → ShopWired
        // Catches any drift the delta sync may miss (e.g., order lock/unlock changes).
        Schedule::job(new SyncFullStockToShopwiredJob())
            ->name('sync-full-stock-to-shopwired')
            ->everyFifteenMinutes()
            ->onOneServer()
            ->withoutOverlapping(15);
    }
}
