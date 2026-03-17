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
 * - Delta sync (every 5 min): incremental, cursor-based, catches direct StockLevel modifications
 * - Full sync (every 10 min): primary sync, catches all changes including order lock/unlock
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
        // Delta: incremental stock sync from Linnworks → ShopWired
        // Catches direct StockLevel modifications (booking in, scrapping, manual adjustments).
        Schedule::job(new SyncDeltaStockToShopwiredJob())
            ->name('sync-delta-stock-to-shopwired')
            ->everyFiveMinutes()
            ->onOneServer()
            ->withoutOverlapping(5);

        // Full: primary stock sync from Linnworks → ShopWired
        // Catches all changes including order lock/unlock — handles the majority of updates.
        Schedule::job(new SyncFullStockToShopwiredJob())
            ->name('sync-full-stock-to-shopwired')
            ->everyTenMinutes()
            ->onOneServer()
            ->withoutOverlapping(10);
    }
}
