<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Application\Linnworks\Enums\OrderSyncTier;
use App\Infrastructure\Jobs\Linnworks\SyncLinnworksOrdersByCursorJob;
use App\Infrastructure\Jobs\Linnworks\SyncLinnworksOrdersJob;
use App\Infrastructure\Jobs\Linnworks\SyncLinnworksStockItemsJob;
use App\Infrastructure\Jobs\Linnworks\SyncLinnworksSuppliersJob;
use App\Infrastructure\Jobs\Linnworks\SyncStockItemsWithCursorJob;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Linnworks Integration Schedule Definitions
 *
 * Syncs ~4k stock items with extended properties from Linnworks to PostgreSQL.
 * Used for inventory lookups and order enrichment.
 */
final class LinnworksScheduleServiceProvider extends ServiceProvider
{
    /**
     * @throws RuntimeException
     */
    public function boot(): void
    {
        // DAILY: Full stock item sync at midnight UTC
        // Safety net for cursor-based sync — ensures all items are eventually consistent
        Schedule::job(new SyncLinnworksStockItemsJob())
            ->name('sync-linnworks-stock-items')
            ->daily()
            ->onOneServer()
            ->withoutOverlapping(60); // 60 min lock - job runs 8-12 min in prod

        // HOURLY: Supplier directory sync
        // Full-replace strategy — small dataset, fetches complete supplier list
        Schedule::job(new SyncLinnworksSuppliersJob())
            ->name('sync-linnworks-suppliers')
            ->hourly()
            ->onOneServer()
            ->withoutOverlapping(10);

        // EVERY 5 MIN: Cursor-based incremental stock item sync
        // Detects recently-modified items and dispatches per-item sync jobs
        Schedule::job(new SyncStockItemsWithCursorJob())
            ->name('sync-stock-items-with-cursor')
            ->everyFiveMinutes()
            ->onOneServer()
            ->withoutOverlapping(5); // 5 min lock - job completes in seconds

        // ── Linnworks Orders Sync (multi-tier redundancy) ──

        // EVERY MINUTE: Cursor-based incremental order sync
        Schedule::job(new SyncLinnworksOrdersByCursorJob())
            ->name('sync-linnworks-orders-cursor')
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping(2); // 2 min lock — job timeout is 90s, runs every minute

        // HOURLY: Orders updated in last hour
        Schedule::job(new SyncLinnworksOrdersJob(OrderSyncTier::Hourly))
            ->name('sync-linnworks-orders-hourly')
            ->hourly()
            ->onOneServer()
            ->withoutOverlapping(15);

        // DAILY: Orders updated in last 2 days
        Schedule::job(new SyncLinnworksOrdersJob(OrderSyncTier::Daily))
            ->name('sync-linnworks-orders-daily')
            ->daily()
            ->onOneServer()
            ->withoutOverlapping(30);

        // WEEKLY: Orders updated in last 2 weeks
        Schedule::job(new SyncLinnworksOrdersJob(OrderSyncTier::Weekly))
            ->name('sync-linnworks-orders-weekly')
            ->weekly()
            ->onOneServer()
            ->withoutOverlapping(60);

        // WEEKLY (offset): Widest safety net — 28 day lookback (v2 API caps at ~30 days)
        // Runs mid-week to stagger with Weekly tier
        Schedule::job(new SyncLinnworksOrdersJob(OrderSyncTier::Monthly))
            ->name('sync-linnworks-orders-monthly')
            ->weeklyOn(3) // Wednesday
            ->onOneServer()
            ->withoutOverlapping(60);
    }
}
