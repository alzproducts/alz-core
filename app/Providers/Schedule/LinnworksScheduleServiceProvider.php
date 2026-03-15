<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Application\Jobs\Linnworks\SyncLinnworksStockItemsJob;
use App\Application\Jobs\Linnworks\SyncStockItemsWithCursorJob;
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

        // EVERY 5 MIN: Cursor-based incremental stock item sync
        // Detects recently-modified items and dispatches per-item sync jobs
        Schedule::job(new SyncStockItemsWithCursorJob())
            ->name('sync-stock-items-with-cursor')
            ->everyFiveMinutes()
            ->onOneServer()
            ->withoutOverlapping(5); // 5 min lock - job completes in seconds
    }
}
